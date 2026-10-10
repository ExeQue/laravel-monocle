import { esc, ms } from './format.js';

export const LENS_COLOR = {
  phase: 'var(--c-phase)', middleware: 'var(--c-mw)', controller: 'var(--c-ctrl)', user: 'var(--brass-2)', db: 'var(--c-db)',
  cache: 'var(--c-cache)', redis: 'var(--c-io)', view: 'var(--c-view)', livewire: 'var(--c-http)', http: 'var(--c-io)', job: 'var(--c-mw)', ai: 'var(--c-ctrl)', mcp: 'var(--c-db)', command: 'var(--c-phase)'
};
export const PHASE_COLOR = { 'Middleware (request)': 'var(--c-mw)', 'Middleware (response)': 'var(--c-mw)', Controller: 'var(--c-ctrl)' };
export const lensOf = (n) => n.kind.split('.')[0];
export const colorOf = (n) => LENS_COLOR[lensOf(n)] ?? 'var(--c-phase)';
const cap = (t) => t.charAt(0).toUpperCase() + t.slice(1);

/** What the bar says, in the words of the operation (Clockwork style). */
export function describe(n) {
  switch (n.kind) {
    case 'cache': { const [type, ...key] = n.name.split(' '); return `${type.toUpperCase()} ${key.join(' ')}`; }
    case 'view': return `Rendering ${n.name}`;
    case 'livewire': return `Livewire ${n.name}`;
    case 'controller': return 'Controller';
    case 'middleware.terminate': return n.name.replace('::terminate', ' terminate');
    case 'phase': return cap(n.name);
    default: return n.name;
  }
}

/** Index the span tree. Redis commands that ran inside a cache call are folded into that cache call. */
export function indexTree(roots, byId = {}) {
  let i = 0;
  const walk = (n, parent) => {
    n.id = 'n' + (i++);
    n.parent = parent;
    n.children = n.children ?? [];
    const caches = n.children.filter((c) => c.kind === 'cache');
    if (caches.length) {
      n.children = n.children.filter((c) => {
        if (c.kind !== 'redis') return true;
        const owner = caches.find((k) => c.s >= k.s - 0.01 && c.e <= k.e + 0.05);
        if (!owner) return true;
        (owner.redis ??= []).push(c);
        return false;
      });
    }
    byId[n.id] = n;
    n.children.forEach((c) => walk(c, n));
  };
  roots.forEach((r) => walk(r, null));
  roots.byId = byId;
  return roots;
}

/** Top level of the timeline: one row per request phase, with middleware split into its request and response side. */
export function phasesOf(roots) {
  if (!roots.some((r) => r.kind === 'phase')) return null;
  const phases = new Map();
  const add = (label, n, node = null) => {
    if (!phases.has(label)) phases.set(label, { label, node, members: [] });
    if (n) phases.get(label).members.push(n);
  };
  const all = []; const flat = (n) => { all.push(n); n.children.forEach(flat); }; roots.forEach(flat);
  const ctrl = all.find((n) => n.kind === 'controller');
  const byTime = (n) => (!ctrl ? 'Controller' : n.s < ctrl.s ? 'Middleware (request)' : n.s >= ctrl.e ? 'Middleware (response)' : 'Controller');
  for (const r of roots) {
    if (r.kind !== 'phase') { add('Other', r); continue; }
    if (r.name === 'middleware (request)' || r.name === 'handle') {
      for (const c of r.children) {
        if (c.kind === 'middleware.request') add('Middleware (request)', c);
        else if (c.kind === 'middleware.response') add('Middleware (response)', c);
        else add(byTime(c), c);
      }
      continue;
    }
    add(cap(r.name), null, r);
    r.children.forEach((c) => add(cap(r.name), c, r));
  }
  return [...phases.values()].map((p) => {
    const ctrl = p.label === 'Controller' ? p.members.find((m) => m.kind === 'controller') : null;
    const range = p.node ?? ctrl ?? { s: Math.min(...p.members.map((m) => m.s)), e: Math.max(...p.members.map((m) => m.e)) };
    return { ...p, id: 'ph:' + p.label, s: range.s, e: range.e, members: p.members.filter((m) => m !== ctrl).concat(ctrl ? ctrl.children : []).sort((a, b) => a.s - b.s) };
  }).sort((a, b) => a.s - b.s);
}

let view = { open: new Set(), shut: new Set(), kinds: new Set() };
const isOpen = (id, fallback) => (view.open.has(id) ? true : view.shut.has(id) ? false : fallback);

function closure(n, lens, out) {
  out.push(n);
  n.children.filter((c) => lensOf(c) === lens).forEach((c) => closure(c, lens, out));
  return out;
}

/** Consecutive same-lens siblings (and their same-lens descendants) fold into one group row. */
function buildRows(children, depth, rows, plain = null) {
  for (let i = 0; i < children.length; i++) {
    const n = children[i];
    const lens = lensOf(n);
    if (lens === 'phase' || lens === plain) {
      rows.push({ type: 'span', node: n, depth });
      buildRows(n.children, depth + 1, rows, plain === lens ? plain : null);
      continue;
    }
    let j = i;
    while (j + 1 < children.length && lensOf(children[j + 1]) === lens) j++;
    const run = children.slice(i, j + 1);
    const members = run.flatMap((r) => closure(r, lens, []));
    if (members.length < 2) {
      rows.push({ type: 'span', node: n, depth });
      buildRows(n.children, depth + 1, rows);
      continue;
    }
    const id = run[0].id;
    const open = isOpen(id, false);
    rows.push({ type: 'group', id, run, members, lens, depth, open });
    if (open) {
      run.forEach((r) => { rows.push({ type: 'span', node: r, depth: depth + 1 }); buildRows(r.children, depth + 2, rows, lens); });
    } else {
      buildRows(members.flatMap((m) => m.children.filter((c) => lensOf(c) !== lens)).sort((a, b) => a.s - b.s), depth + 1, rows);
    }
    i = j;
  }
  return rows;
}

function lanes(items) {
  const ends = [];
  return items.slice().sort((a, b) => a.s - b.s).map((it) => {
    let lane = ends.findIndex((e) => e <= it.s + 1e-6);
    if (lane === -1) { lane = ends.length; ends.push(0); }
    ends[lane] = it.e;
    return { it, lane };
  });
}

function groupLabel(g) {
  const own = g.members.filter((m) => m.kind === g.lens);
  const primary = own.length ? own : g.members;
  const names = [...new Set(primary.map(describe))];
  return names.length === 1 ? `${primary.length} × ${names[0]}` : `${primary.length} ${g.lens} events`;
}

export const phaseIndex = {};

/**
 * Render the timeline of a capture. model = { tree (from indexTree), duration }, state = { open, shut, kinds } (Sets).
 */
export function renderTimeline(model, state) {
  view = state;
  const c = model;
  const roots = model.tree;
  const all = []; const flat = (n) => { all.push(n); n.children.forEach(flat); }; roots.forEach(flat);
  const lensList = [...new Set(all.map(lensOf))];
  const end = c.duration;
  const pct = (v) => Math.max(0, Math.min(100, (v / end) * 100));
  const phases = phasesOf(roots);

  /** Clockwork-style label: after the bar in the left half, before it in the right half, inside when the bar is wide. */
  const label = (text, s, e, extra = '') => {
    const left = pct(s); const right = pct(e);
    const pos = right - left > 38 ? `left:${left}%;width:${right - left}%;padding-left:6px` : left > 50 ? `right:${100 - left}%;text-align:right;padding-right:6px` : `left:${right}%;padding-left:6px`;
    const cls = right - left > 38 ? 'inside' : '';
    return `<span class="wf-label ${cls}" style="${pos}">${esc(text)}${extra}</span>`;
  };

  const segments = (items, color, top) => {
    const packed = lanes(items);
    const count = Math.max(1, ...packed.map((l) => l.lane + 1));
    const h = count === 1 ? 12 : Math.max(4, Math.floor(18 / count));
    return {
      height: Math.max(26, top + count * (h + 2) + 6),
      html: packed.map(({ it, lane }, k) => `<span class="wf-seg${k % 2 ? ' alt' : ''}" data-node="${it.id}" title="${esc(describe(it))} · ${ms(it.e - it.s)}" style="left:${pct(it.s)}%;width:${pct(it.e) - pct(it.s)}%;top:${top + lane * (h + 2)}px;height:${h}px;background:${color ?? colorOf(it)}"></span>`).join('')
    };
  };

  const spanRow = ({ node: n, depth }) => `<div class="wf-row wf-click" data-node="${n.id}" tabindex="0" aria-haspopup="dialog">
      <span class="wf-name" style="padding-left:${10 + depth * 12}px"><span class="kind">${n.kind}</span></span>
      <div class="wf-track"><span class="wf-bar" title="${esc(describe(n))} · ${ms(n.e - n.s)}" style="left:${pct(n.s)}%;width:${pct(n.e) - pct(n.s)}%;background:${colorOf(n)}"></span>${label(describe(n), n.s, n.e)}</div>
      <span class="wf-dur num">${ms(n.e - n.s)}</span>
    </div>`;

  const toggleCell = (id, open, depth, text) => `<button class="wf-name wf-toggle" data-toggle="${id}" data-open="${open}" aria-expanded="${open}" style="padding-left:${10 + depth * 12}px"><span class="chev" aria-hidden="true">▸</span>${text}</button>`;

  const groupRow = (g) => {
    const from = Math.min(...g.members.map((m) => m.s));
    const to = Math.max(...g.members.map((m) => m.e));
    const color = LENS_COLOR[g.lens] ?? 'var(--c-phase)';
    const wrappers = g.members.filter((m) => m.children.some((ch) => lensOf(ch) === g.lens));
    const leaves = g.members.filter((m) => !wrappers.includes(m));
    const wl = lanes(wrappers);
    const wCount = wl.length ? Math.max(...wl.map((l) => l.lane)) + 1 : 0;
    const seg = segments(leaves, color, 7 + wCount * 3 + (wCount ? 2 : 0));
    return `<div class="wf-row wf-group${g.open ? ' open' : ''}" style="height:${g.open ? 26 : seg.height}px">
      ${toggleCell(g.id, g.open, g.depth, `<span class="kind">${g.lens}</span>`)}
      <div class="wf-track">
        ${g.open ? `<span class="wf-range" style="left:${pct(from)}%;width:${pct(to) - pct(from)}%;border-color:${color}"></span>` : `
          ${wl.map(({ it, lane }) => `<span class="wf-wrap" style="left:${pct(it.s)}%;width:${pct(it.e) - pct(it.s)}%;top:${7 + lane * 3}px;background:${color}"></span>`).join('')}
          ${seg.html}`}
        ${label(groupLabel(g), from, to)}
      </div>
      <span class="wf-dur num">${ms(to - from)}<span class="cnt">${g.members.length} spans</span></span>
    </div>`;
  };

  const phaseRow = (p) => {
    const color = PHASE_COLOR[p.label] ?? 'var(--c-phase)';
    const collapsible = p.members.length > 0;
    const open = collapsible && isOpen(p.id, !p.label.startsWith('Middleware'));
    const seg = !open && collapsible ? segments(p.members, p.label.startsWith('Middleware') ? color : null, 8) : null;
    const count = p.label.startsWith('Middleware') ? ` <span class="cnt-inline">${p.members.filter((m) => m.kind.startsWith('middleware')).length} middleware</span>` : '';
    const name = `<span class="phase-name">${p.label}</span>`;
    phaseIndex[p.id] = p;
    return `<div class="wf-row wf-phase${open ? ' open' : ''}" style="height:${seg ? seg.height : 28}px">
      ${collapsible ? toggleCell(p.id, open, 0, name) : `<span class="wf-name">${name}</span>`}
      <div class="wf-track">
        ${seg ? seg.html : `<span class="wf-bar phase" data-phase="${p.id}" style="left:${pct(p.s)}%;width:${pct(p.e) - pct(p.s)}%;background:${color}"></span>`}
        ${label(p.label, p.s, p.e, count)}
      </div>
      <span class="wf-dur num">${ms(p.e - p.s)}</span>
    </div>${open ? buildRows(p.members, 1, [], p.label.startsWith('Middleware') ? 'middleware' : null).map(row).join('') : ''}`;
  };

  const row = (r) => (r.type === 'group' ? groupRow(r) : spanRow(r));

  const body = view.kinds.size
    ? all.filter((n) => view.kinds.has(lensOf(n))).map((node) => spanRow({ node, depth: 0 })).join('')
    : phases ? phases.map(phaseRow).join('') : buildRows(roots, 0, []).map(row).join('');

  const ticks = [0, .25, .5, .75, 1].map((f) => `<span style="left:${f * 100}%">${ms(end * f)}</span>`).join('');
  return `
    <div class="tl-bar">
      <div class="kinds" role="group" aria-label="Lenses">
        ${lensList.map((k) => `<button class="chip" data-kind="${k}" aria-pressed="${view.kinds.has(k)}"><i class="sw" style="background:${LENS_COLOR[k] ?? 'var(--c-phase)'}"></i>${k}</button>`).join('')}
      </div>
      ${view.kinds.size ? '' : `<div class="tl-actions"><button class="link" data-expand="1">Expand all</button><button class="link" data-collapse="1">Collapse all</button></div>`}
    </div>
    <div class="waterfall"><div class="wf-inner">
      <div class="wf-scale"><span style="padding-left:10px"></span><div class="wf-ticks">${ticks}</div><span style="text-align:right;padding-right:10px">time</span></div>
      ${body}
    </div></div>`;
}

