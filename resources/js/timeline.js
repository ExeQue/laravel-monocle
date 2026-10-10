import { esc, ms } from './format.js';
import { cls } from './classes.js';

export const LENS_COLOR = {
  phase: 'var(--c-phase)', middleware: 'var(--c-mw)', controller: 'var(--c-ctrl)', user: 'var(--brass-2)', db: 'var(--c-db)',
  cache: 'var(--c-cache)', redis: 'var(--c-io)', view: 'var(--c-view)', livewire: 'var(--c-http)', http: 'var(--c-io)', job: 'var(--c-mw)', ai: 'var(--c-ctrl)', mcp: 'var(--c-db)', command: 'var(--c-phase)'
};
export const PHASE_COLOR = { 'Middleware (request)': 'var(--c-mw)', 'Middleware (response)': 'var(--c-mw)', Controller: 'var(--c-ctrl)' };
export const lensOf = (n) => n.kind.split('.')[0];
/** A span's own colour (a SpanColor name or a hex value) wins over its kind's. */
export const colorOf = (n) => {
  if (n.color) return n.color.startsWith('#') ? n.color : `var(--span-${n.color})`;
  return LENS_COLOR[lensOf(n)] ?? 'var(--c-phase)';
};
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


function closure(n, lens, out) {
  out.push(n);
  n.children.filter((c) => lensOf(c) === lens).forEach((c) => closure(c, lens, out));
  return out;
}

/** Consecutive same-lens siblings (and their same-lens descendants) fold into one group row. */
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
const COLUMNS = 'grid grid-cols-[190px_minmax(0,1fr)_76px] items-center';
const ROW = `wf-row ${COLUMNS} h-[26px] border-b border-line text-[12.5px] last:border-b-0 hover:bg-sunk`;
const NAME = 'flex items-baseline gap-1.5 overflow-hidden text-ellipsis whitespace-nowrap';
const KIND = 'font-mono text-[10px] text-muted';
const TRACK = 'relative h-full overflow-hidden bg-[linear-gradient(to_right,var(--line)_1px,transparent_1px)] bg-[length:25%_100%]';
const DUR = 'pr-2.5 text-right font-mono text-[11.5px] tabular-nums text-muted';
const COUNT = 'block text-[10px] leading-tight text-muted';

/**
 * Render the timeline of a capture once; folding is left to Alpine (tlOpen /
 * tlToggle on the dashboard component), so rows animate with x-collapse.
 * model = { tree (from indexTree), duration }, kinds = Set of lens filters.
 * Returns the html and every foldable id with its default state.
 */
export function renderTimeline(model, kinds) {
  const roots = model.tree;
  const all = []; const flat = (n) => { all.push(n); n.children.forEach(flat); }; roots.forEach(flat);
  const lensList = [...new Set(all.map(lensOf))];
  const end = model.duration;
  const pct = (v) => Math.max(0, Math.min(100, (v / end) * 100));
  const phases = phasesOf(roots);
  const foldable = [];

  const label = (text, s, e, extra = '') => {
    const left = pct(s); const right = pct(e);
    const inside = right - left > 38;
    const pos = inside ? `left:${left}%;width:${right - left}%;padding-left:6px` : left > 50 ? `right:${100 - left}%;text-align:right;padding-right:6px` : `left:${right}%;padding-left:6px`;
    const look = inside ? 'max-w-none font-medium text-on-brass [text-shadow:0_0_2px_rgba(0,0,0,.25)]' : 'max-w-[70%] text-fg';
    return `<span class="pointer-events-none absolute top-1/2 z-[1] -translate-y-1/2 overflow-hidden text-ellipsis whitespace-nowrap text-[11.5px] ${look}" style="${pos}">${esc(text)}${extra}</span>`;
  };

  const segments = (items, color, top) => {
    const packed = lanes(items);
    const count = Math.max(1, ...packed.map((l) => l.lane + 1));
    const h = count === 1 ? 12 : Math.max(4, Math.floor(18 / count));
    return {
      height: Math.max(26, top + count * (h + 2) + 6),
      html: packed.map(({ it, lane }, k) => `<span class="absolute min-w-0.5 cursor-pointer rounded-[1px] shadow-[0_0_0_1px_var(--panel)] hover:brightness-110 ${k % 2 ? 'brightness-[.82] saturate-[1.1]' : ''}" data-node="${it.id}" title="${esc(describe(it))} · ${ms(it.e - it.s)}" style="left:${pct(it.s)}%;width:${pct(it.e) - pct(it.s)}%;top:${top + lane * (h + 2)}px;height:${h}px;background:${it.color ? colorOf(it) : (color ?? colorOf(it))}"></span>`).join(''),
    };
  };

  const spanRow = (n, depth) => `<div class="${ROW} wf-click cursor-pointer focus-visible:outline-offset-[-2px]" data-node="${n.id}" tabindex="0" aria-haspopup="dialog">
      <span class="${NAME}" style="padding-left:${10 + depth * 12}px"><span class="${KIND}">${n.kind}</span></span>
      <div class="${TRACK}"><span class="absolute top-[7px] h-3 min-w-0.5 rounded-sm shadow-[0_0_0_1px_var(--panel)]" title="${esc(describe(n))} · ${ms(n.e - n.s)}" style="left:${pct(n.s)}%;width:${pct(n.e) - pct(n.s)}%;background:${colorOf(n)}"></span>${label(describe(n), n.s, n.e)}</div>
      <span class="${DUR}">${ms(n.e - n.s)}</span>
    </div>`;

  const open = (id, def) => `tlOpen('${id}', ${def})`;

  const toggleCell = (id, def, depth, text) => `<button type="button" class="wf-toggle ${NAME} h-full items-center text-left text-[12.5px]" @click="tlToggle('${id}', ${def})" :aria-expanded="${open(id, def)}" style="padding-left:${6 + depth * 12}px"><svg class="size-3.5 shrink-0 self-center text-brass transition-transform duration-200 ease-out" :class="${open(id, def)} && 'rotate-90'" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 3.5 10.5 8 6 12.5"/></svg>${text}</button>`;

  const fold = (id, def, html) => (html ? `<div x-show="${open(id, def)}" x-collapse${def ? '' : ' x-cloak'}>${html}</div>` : '');

  /** Consecutive same-lens siblings (and their same-lens descendants) fold into one group row. */
  const nodes = (children, depth, plain = null) => {
    let html = '';
    for (let i = 0; i < children.length; i++) {
      const n = children[i];
      const lens = lensOf(n);
      if (lens === 'phase' || lens === plain) {
        html += spanRow(n, depth) + nodes(n.children, depth + 1, plain === lens ? plain : null);
        continue;
      }
      let j = i;
      while (j + 1 < children.length && lensOf(children[j + 1]) === lens) j++;
      const run = children.slice(i, j + 1);
      const members = run.flatMap((r) => closure(r, lens, []));
      if (members.length < 2) {
        html += spanRow(n, depth) + nodes(n.children, depth + 1);
        continue;
      }
      html += group({ id: run[0].id, run, members, lens, depth });
      i = j;
    }
    return html;
  };

  const group = (g) => {
    foldable.push({ id: g.id, def: false });
    const from = Math.min(...g.members.map((m) => m.s));
    const to = Math.max(...g.members.map((m) => m.e));
    const color = LENS_COLOR[g.lens] ?? 'var(--c-phase)';
    const wrappers = g.members.filter((m) => m.children.some((ch) => lensOf(ch) === g.lens));
    const leaves = g.members.filter((m) => !wrappers.includes(m));
    const wl = lanes(wrappers);
    const wCount = wl.length ? Math.max(...wl.map((l) => l.lane)) + 1 : 0;
    const seg = segments(leaves, color, 7 + wCount * 3 + (wCount ? 2 : 0));
    const external = g.members.flatMap((m) => m.children.filter((c) => lensOf(c) !== g.lens)).sort((a, b) => a.s - b.s);
    const header = `<div class="${ROW} bg-[color-mix(in_srgb,var(--brass-soft)_45%,transparent)] transition-[height] duration-200" :style="${open(g.id, false)} ? 'height:26px' : 'height:${seg.height}px'" style="height:${seg.height}px">
      ${toggleCell(g.id, false, g.depth, `<span class="${KIND}">${g.lens}</span>`)}
      <div class="${TRACK} self-stretch">
        <span x-show="${open(g.id, false)}" x-cloak class="absolute top-1/2 -mt-[3px] h-1.5 rounded-b-sm border-[1.5px] border-t-0 opacity-70" style="left:${pct(from)}%;width:${pct(to) - pct(from)}%;border-color:${color}"></span>
        <div x-show="!${open(g.id, false)}">
          ${wl.map(({ it, lane }) => `<span class="absolute h-0.5 min-w-0.5 rounded-[1px] opacity-55" style="left:${pct(it.s)}%;width:${pct(it.e) - pct(it.s)}%;top:${7 + lane * 3}px;background:${color}"></span>`).join('')}
          ${seg.html}
        </div>
        ${label(groupLabel(g), from, to)}
      </div>
      <span class="${DUR} leading-tight">${ms(to - from)}<span class="${COUNT}">${g.members.length} spans</span></span>
    </div>`;
    const level = (m) => { let d = 0; for (let p = m; !g.run.includes(p); p = p.parent) d++; return d; };
    const expanded = g.members.map((m) => spanRow(m, g.depth + 1 + level(m))).join('');
    return header + fold(g.id, false, expanded) + nodes(external, g.depth + 1);
  };

  const phaseRow = (p) => {
    const color = PHASE_COLOR[p.label] ?? 'var(--c-phase)';
    const middleware = p.label.startsWith('Middleware');
    const def = !middleware;
    const collapsible = p.members.length > 0;
    const count = middleware ? ` <span class="font-mono text-[10.5px] opacity-80">${p.members.filter((m) => m.kind.startsWith('middleware')).length} middleware</span>` : '';
    const name = `<span class="text-[12.5px] font-semibold">${p.label}</span>`;
    phaseIndex[p.id] = p;
    if (collapsible) foldable.push({ id: p.id, def });
    const seg = collapsible ? segments(p.members, middleware ? color : null, 8) : null;
    const bar = `<span class="absolute top-1.5 h-4 min-w-0.5 cursor-pointer rounded-sm opacity-85 hover:brightness-110" data-phase="${p.id}" style="left:${pct(p.s)}%;width:${pct(p.e) - pct(p.s)}%;background:${color}"></span>`;
    const header = `<div class="${ROW} bg-sunk ${collapsible ? 'transition-[height] duration-200' : ''}" style="height:${collapsible && !def ? seg.height : 28}px" ${collapsible ? `:style="${open(p.id, def)} ? 'height:28px' : 'height:${seg.height}px'"` : ''}>
      ${collapsible ? toggleCell(p.id, def, 0, name) : `<span class="${NAME} pl-2.5">${name}</span>`}
      <div class="${TRACK}">
        ${collapsible ? `<div x-show="${open(p.id, def)}"${def ? '' : ' x-cloak'}>${bar}</div><div x-show="!${open(p.id, def)}"${def ? ' x-cloak' : ''}>${seg.html}</div>` : bar}
        ${label(p.label, p.s, p.e, count)}
      </div>
      <span class="${DUR}">${ms(p.e - p.s)}</span>
    </div>`;
    return header + (collapsible ? fold(p.id, def, nodes(p.members, 1, middleware ? 'middleware' : null)) : '');
  };

  const body = kinds.size
    ? all.filter((n) => kinds.has(lensOf(n))).map((n) => spanRow(n, 0)).join('')
    : phases ? phases.map(phaseRow).join('') : nodes(roots, 0);

  const ticks = [0, .25, .5, .75, 1].map((f) => `<span class="absolute top-[7px] -translate-x-1/2 first:translate-x-0 last:-translate-x-full" style="left:${f * 100}%">${ms(end * f)}</span>`).join('');
  const html = `
    <div class="mb-2.5 flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
      <div class="flex flex-wrap gap-1.5" role="group" aria-label="Lenses">
        ${lensList.map((k) => `<button type="button" class="${cls.chip} gap-1.5" data-kind="${k}" aria-pressed="${kinds.has(k)}"><i class="${cls.swatch} size-2" style="background:${LENS_COLOR[k] ?? 'var(--c-phase)'}"></i>${k}</button>`).join('')}
      </div>
      ${kinds.size ? '' : `<div class="flex gap-3.5 text-[13px]"><button type="button" class="${cls.link}" @click="tlAll(true)">Expand all</button><button type="button" class="${cls.link}" @click="tlAll(false)">Collapse all</button></div>`}
    </div>
    <div class="scroll-quiet overflow-x-auto rounded-md border border-line bg-panel"><div class="min-w-[640px]">
      <div class="${COLUMNS} h-7 border-b border-line font-mono text-[11px] text-muted"><span class="pl-2.5"></span><div class="relative h-full">${ticks}</div><span class="pr-2.5 text-right">time</span></div>
      ${body}
    </div></div>`;

  return { html, foldable };
}
