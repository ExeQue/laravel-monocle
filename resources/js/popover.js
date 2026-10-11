import { esc, ms, fmtValue, maybeLink, copyButton, isThrowable, exceptionHtml, humanize } from './format.js';
import { cls } from './classes.js';

const SEC = 'grid gap-1 border-t border-line pt-2';
const K = 'text-[11px] uppercase tracking-wider text-muted';
const SUB = 'text-xs text-muted [overflow-wrap:anywhere]';
import { colorOf, describe, PHASE_COLOR, phaseIndex } from './timeline.js';

const pathOf = (n) => { const out = []; for (let p = n.parent; p; p = p.parent) out.unshift(describe(p)); return out; };

export const originHtml = (o) => !o || !o.file ? '' : `
  <div class="${SEC}"><div class="${K}">Origin</div>
    <div class="font-mono text-xs text-brass break-all">${maybeLink(o.file + (o.line ? ':' + o.line : ''), o.link)}${o.vendor ? ' <span class="ml-1.5 rounded border border-line px-1 font-mono text-[10px] uppercase tracking-wider text-muted">vendor</span>' : ''}</div>
    ${o.call ? `<div class="${SUB} font-mono">${maybeLink(o.call, o.call_link)}</div>` : ''}
    ${o.via ? `<div class="${SUB}">via <span class="font-mono">${maybeLink(o.via.call ?? o.via.file, o.via.call_link ?? o.via.link)}</span> <span class="font-mono">(${maybeLink(o.via.file + (o.via.line ? ':' + o.via.line : ''), o.via.link)})</span></div>` : ''}
    ${o.frames?.length ? `<details><summary class="cursor-pointer text-xs text-muted">Call chain (${o.frames.length})</summary><ol class="mt-1 grid gap-0.5 pl-4.5 text-[11.5px] [overflow-wrap:anywhere] list-decimal">${o.frames.map((f) => `<li class="font-mono">${maybeLink((f.file ?? '') + (f.line ? ':' + f.line : ''), f.link)} ${maybeLink(f.call ?? '', f.call_link)}</li>`).join('')}</ol></details>` : ''}
  </div>`;


const codeBlock = (title, text) => `<div class="flex items-center gap-1.5"><div class="${K}">${title}</div>${copyButton(text, title.toLowerCase())}</div><pre class="m-0 whitespace-pre-wrap rounded-md bg-sunk p-2 font-mono text-xs [overflow-wrap:anywhere]">${esc(text)}</pre>`;

const placeholdersHtml = (sql) => `<div class="${SEC}">${codeBlock('Query with placeholders', sql)}</div>`;

function metaRows(meta) {
  const links = meta._links ?? {};
  return Object.entries(meta)
    .filter(([k, v]) => k !== '_links' && k !== 'n' && v !== null && v !== '' && !(Array.isArray(v) && v.length === 0))
    .map(([k, v]) => {
      const bytes = (n) => (n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : n >= 1024 ? (n / 1024).toFixed(1) + ' KB' : n + ' B');
      const text = typeof v !== 'number' ? fmtValue(v)
        : k === 'duration' || k === 'time' || k.endsWith('_ms') ? ms(v)
          : k === 'bytes' || k.endsWith('_bytes') || k === 'size' ? bytes(v)
            : k === 'ttl' ? `${v} s` : fmtValue(v);
      const label = esc(k === 'time' ? 'Started at' : humanize(k.replace(/_(bytes|ms)$/, '')));
      // Details show values in full (class names included), each with a copy button.
      // A plain map (rules per field, parameters, context) as a small nested table.
      if (v && typeof v === 'object' && !Array.isArray(v) && !isThrowable(v) && Object.keys(v).length && Object.values(v).every((x) => x === null || typeof x !== 'object' || Array.isArray(x))) {
        return `<tr class="group"><th class="w-0 whitespace-nowrap py-0.5 pr-4 text-left align-top text-xs font-medium text-muted">${label}</th><td class="py-0.5 text-xs"><table class="border-collapse">${Object.entries(v).map(([field, x]) => `<tr class="group/copy"><td class="pr-3 align-top font-mono text-muted">${esc(field)}</td><td class="font-mono [overflow-wrap:anywhere]">${esc(Array.isArray(x) ? x.map(fmtValue).join(' | ') : k === 'rules' ? String(x).split('|').join(' | ') : fmtValue(x))}</td><td class="pl-2">${copyButton(Array.isArray(x) ? x.map(fmtValue).join('|') : fmtValue(x), `${k} ${field}`, { hover: true })}</td></tr>`).join('')}</table></td><td class="w-5 py-0.5 align-top">${copyButton(JSON.stringify(v), k)}</td></tr>`;
      }
      if (isThrowable(v)) return `<tr class="group"><th class="w-0 whitespace-nowrap py-0.5 pr-4 text-left align-top text-xs font-medium text-muted">${label}</th><td class="py-0.5 text-xs">${exceptionHtml(v, { full: true })}</td><td class="w-5 py-0.5 align-top">${copyButton(`${v.class}: ${v.message}`, k)}</td></tr>`;
      const value = links[k] ? `<a class="underline decoration-brass underline-offset-2 hover:text-brass" href="${esc(links[k])}">${esc(text)}</a>` : esc(text);
      return `<tr class="group"><th class="w-0 whitespace-nowrap py-0.5 pr-4 text-left align-top text-xs font-medium text-muted">${label}</th><td class="py-0.5 font-mono text-xs [overflow-wrap:anywhere]">${value}</td><td class="w-5 py-0.5 align-top">${copyButton(text, k)}</td></tr>`;
    })
    .join('');
}

function nodeDetails(model, n) {
  const d = n.d ?? {};
  const dur = n.e - n.s;
  const meta = d.meta ?? {};
  const path = pathOf(n);
  const rows = metaRows(meta);
  return `
    <div class="flex items-center gap-2"><i class="${cls.swatch}" style="background:${colorOf(n)}"></i><span class="${cls.type}">${esc(n.kind)}</span><button class="ml-auto px-0.5 text-lg leading-none text-muted" data-pop-close aria-label="Close">×</button></div>
    <div class="font-semibold [overflow-wrap:anywhere]">${esc(d.sql ? [meta.type ?? 'query', ...(meta.tables ?? [])].join(' ') : describe(n))}</div>
    ${path.length ? `<div class="text-[11.5px] text-muted [overflow-wrap:anywhere]">${path.map(esc).join(' <span class="text-brass">›</span> ')}</div>` : ''}
    <div class="grid grid-cols-[repeat(auto-fill,minmax(80px,1fr))] gap-2">
      <div class="grid gap-0.5"><span class="${K}">Duration</span><b class="tabular-nums">${ms(dur)}</b></div>
      <div class="grid gap-0.5"><span class="${K}">Starts at</span><b class="tabular-nums">${ms(n.s)}</b></div>
      <div class="grid gap-0.5"><span class="${K}">Of total</span><b class="tabular-nums">${((dur / Math.max(model.duration, 0.001)) * 100).toFixed(1)} %</b></div>
      ${n.children.length ? `<div class="grid gap-0.5"><span class="${K}">Children</span><b class="tabular-nums">${n.children.length}</b></div>` : ''}
    </div>
    ${d.sql ? `<div class="${SEC}">${codeBlock('Query', d.sql)}</div>` : ''}
    ${d.placeholders ? placeholdersHtml(d.placeholders) : ''}
    ${rows ? `<div class="${SEC}"><table class="w-full border-collapse">${rows}</table></div>` : ''}
    ${n.redis?.length ? `<div class="${SEC}"><div class="${K}">Redis commands</div>${n.redis.map((r) => `<div class="${SUB} font-mono">${esc(r.name)} <span class="tabular-nums">${ms(r.e - r.s)}</span></div>`).join('')}</div>` : ''}
    ${originHtml(d.origin)}`;
}

/** Everything about one row from a section table: every field in full, the query, the origin. */
export function rowBody(row) {
  // A row that is itself an exception (the exceptions section) leads with its chain.
  const thrown = isThrowable(row) && Array.isArray(row.previous);
  // Prose (prompts, responses, bodies) reads better as a block than as a table cell.
  const prose = ['prompt', 'response', 'body', 'text', 'instructions'].filter((k) => typeof row[k] === 'string' && /\s/.test(row[k].trim()));
  const hidden = ['origin', 'sql_with_bindings', 'sql', 'shape', 'shape_hash', 'frames', ...prose, ...(thrown ? ['class', 'message', 'previous', 'file'] : []), ...(row.code === 0 ? ['code'] : [])];
  const frames = Array.isArray(row.frames) && row.frames.length ? row.frames : null;
  const meta = Object.fromEntries(Object.entries(row).filter(([k]) => !hidden.includes(k)));
  const rows = metaRows(meta);
  const sql = row.sql_with_bindings ?? row.sql;
  return `
    ${thrown ? `<div class="${SEC} border-t-0 pt-0"><div class="flex items-center gap-1.5"><div class="${K}">Exception</div>${copyButton([row, ...row.previous].map((x) => `${x.class}: ${x.message}`).join('\n'), 'exception')}</div><div class="rounded-md border border-line bg-panel p-2.5 text-xs">${exceptionHtml(row, { full: true })}</div></div>` : ''}
    ${sql ? `<div class="${SEC} border-t-0 pt-0">${codeBlock('Query', sql)}</div>` : ''}
    ${row.sql && row.sql_with_bindings && row.sql !== row.sql_with_bindings ? placeholdersHtml(row.sql) : ''}
    ${rows ? `<div class="${SEC} ${sql || thrown ? '' : 'border-t-0 pt-0'}"><table class="w-full border-collapse">${rows}</table></div>` : ''}
    ${prose.map((k) => `<div class="${SEC}">${codeBlock(humanize(k), row[k])}</div>`).join('')}
    ${frames ? `<div class="${SEC}"><div class="flex items-center gap-1.5"><div class="${K}">Stack (${frames.length})</div>${copyButton(frames.map((f) => (typeof f === 'string' ? f : `${f.file} ${f.call ?? ''}`.trim())).join('\n'), 'stack')}</div><ol class="m-0 grid list-decimal gap-0.5 pl-6 text-[11.5px] [overflow-wrap:anywhere]">${frames.map((f) => (typeof f === 'string' ? `<li class="font-mono">${esc(f)}</li>` : `<li class="font-mono"><span class="text-brass">${maybeLink(f.file, f.link)}</span> ${f.call ? `<span class="text-muted">${maybeLink(f.call, f.call_link)}</span>` : ''}</li>`)).join('')}</ol></div>` : ''}
    ${originHtml(row.origin)}`;
}

/** Details of one row from a section table (a query, a cache call, a view ...) in the popover. */
export function rowDetails(row, section) {
  const title = row.sql_with_bindings ?? row.sql ?? row.key ?? row.view ?? row.url ?? row.name ?? row.message ?? row.ability ?? section;
  const sql = row.sql_with_bindings ?? row.sql;
  return `
    <div class="flex items-center gap-2"><span class="${cls.type}">${esc(section)}</span>${row.n ? `<span class="font-mono text-[11px] text-muted">#${esc(row.n)}</span>` : ''}<button class="ml-auto px-0.5 text-lg leading-none text-muted" data-pop-close aria-label="Close">×</button></div>
    ${sql ? '' : `<div class="font-semibold [overflow-wrap:anywhere]">${esc(String(title))}</div>`}
    ${rowBody(row)}`;
}

function phaseDetails(model, p) {
  const slowest = [...p.members].sort((a, b) => (b.e - b.s) - (a.e - a.s)).slice(0, 5);
  const dur = p.e - p.s;
  return `
    <div class="flex items-center gap-2"><i class="${cls.swatch}" style="background:${PHASE_COLOR[p.label] ?? 'var(--c-phase)'}"></i><span class="${cls.type}">phase</span><button class="ml-auto px-0.5 text-lg leading-none text-muted" data-pop-close aria-label="Close">×</button></div>
    <div class="font-semibold [overflow-wrap:anywhere]">${esc(p.label)}</div>
    <div class="grid grid-cols-[repeat(auto-fill,minmax(80px,1fr))] gap-2">
      <div class="grid gap-0.5"><span class="${K}">Duration</span><b class="tabular-nums">${ms(dur)}</b></div>
      <div class="grid gap-0.5"><span class="${K}">From</span><b class="tabular-nums">${ms(p.s)}</b></div>
      <div class="grid gap-0.5"><span class="${K}">Of total</span><b class="tabular-nums">${((dur / Math.max(model.duration, 0.001)) * 100).toFixed(1)} %</b></div>
      <div class="grid gap-0.5"><span class="${K}">Spans</span><b class="tabular-nums">${p.members.length}</b></div>
    </div>
    ${slowest.length ? `<div class="${SEC}"><div class="${K}">Slowest</div>${slowest.map((m) => `<button class="group flex w-full justify-between gap-2.5 border-b border-dashed border-line py-1 text-left text-[12.5px]" data-pop-node="${m.id}"><span class="min-w-0 overflow-hidden text-ellipsis whitespace-nowrap group-hover:text-brass">${esc(describe(m))}</span><span class="tabular-nums">${ms(m.e - m.s)}</span></button>`).join('')}</div>` : ''}`;
}

/**
 * Click a timeline row, block or phase bar to open its details next to the
 * click. getModel() returns the timeline model of the open capture.
 */
export function setupPopover(root, getModel) {
  const pop = document.createElement('div');
  pop.className = 'pop pop-arrow scroll-quiet fixed z-50 grid max-h-[min(70vh,520px)] w-[min(380px,calc(100vw-32px))] gap-2.5 overflow-auto rounded-lg border border-line bg-panel p-3.5 text-[13px] text-fg shadow-[0_12px_32px_rgba(0,0,0,.18),0_2px_6px_rgba(0,0,0,.08)]';
  pop.hidden = true;
  pop.setAttribute('role', 'dialog');
  pop.setAttribute('aria-label', 'Details');
  document.body.append(pop);
  let target = null;

  const open = (el, html, clientX) => {
    pop.innerHTML = html;
    pop.hidden = false;
    target = el;

    // Narrow screens: a sheet along the bottom edge instead of a bubble next to the click.
    if (innerWidth < 640) {
      Object.assign(pop.style, { left: '0', right: '0', top: 'auto', bottom: '0', width: '100%', maxHeight: '70dvh', borderRadius: '12px 12px 0 0' });
      pop.dataset.side = 'sheet';
      pop.querySelector('[data-pop-close]')?.focus({ preventScroll: true });
      return;
    }

    Object.assign(pop.style, { right: '', bottom: '', width: '', maxHeight: '', borderRadius: '' });
    const r = el.getBoundingClientRect();
    offsetX = (clientX ?? r.left + Math.min(r.width, 40) / 2) - r.left;
    side = null;
    place();
    pop.querySelector('[data-pop-close]')?.focus({ preventScroll: true });
  };

  let offsetX = 0;
  let side = null;

  /** Position next to the target; the side is picked once and kept while scrolling. */
  const place = () => {
    const r = target.getBoundingClientRect();
    const w = pop.offsetWidth;
    const h = pop.offsetHeight;
    const x = r.left + offsetX;
    const left = Math.max(16, Math.min(x - 28, innerWidth - w - 16));
    const fitsBelow = r.bottom + 10 + h <= innerHeight - 8;
    const fitsAbove = r.top - 10 - h >= 8;
    if (side === null || (side === 'below' && !fitsBelow && fitsAbove) || (side === 'above' && !fitsAbove && fitsBelow)) {
      side = fitsBelow || !fitsAbove ? 'below' : 'above';
    }
    const top = side === 'below' ? r.bottom + 10 : r.top - h - 10;
    pop.style.left = left + 'px';
    pop.style.top = Math.max(8, Math.min(top, innerHeight - h - 8)) + 'px';
    pop.dataset.side = side;
    pop.style.setProperty('--arrow', Math.max(14, Math.min(x - left, w - 14)) + 'px');
  };

  const close = () => {
    if (pop.hidden) return;
    pop.hidden = true;
    target?.focus?.({ preventScroll: true });
    target = null;
  };

  const show = (el, clientX) => {
    const model = getModel();
    if (!model) return;
    if (el.dataset.phase) return open(el, phaseDetails(model, phaseIndex[el.dataset.phase]), clientX);
    const n = model.tree.byId[el.dataset.node];
    if (n) open(el, nodeDetails(model, n), clientX);
  };

  document.addEventListener('click', (e) => {
    if (e.target.closest('.pop')) {
      if (e.target.closest('[data-pop-close]')) close();
      const jump = e.target.closest('[data-pop-node]');
      if (jump) {
        const el = root.querySelector(`[data-node="${jump.dataset.popNode}"]`);
        const model = getModel();
        el ? show(el) : open(target, nodeDetails(model, model.tree.byId[jump.dataset.popNode]));
      }
      return;
    }
    const hit = root.contains(e.target) && !e.target.closest('.wf-toggle') && e.target.closest('[data-node], [data-phase]');
    if (hit) {
      e.stopPropagation();
      hit === target && !pop.hidden ? close() : show(hit, e.clientX);
      return;
    }
    close();
  }, true);

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') close();
    if ((e.key === 'Enter' || e.key === ' ') && e.target.matches?.('.wf-click')) { e.preventDefault(); show(e.target); }
  });
  addEventListener('resize', close);
  document.addEventListener('scroll', (e) => {
    if (pop.hidden || pop.contains(e.target) || pop.dataset.side === 'sheet') return;
    const r = target?.getBoundingClientRect();
    if (!r || !target.isConnected || r.bottom < 0 || r.top > innerHeight) return close();
    place();
  }, true);

  return { close, openDetails: open };
}
