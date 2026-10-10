import { esc, ms, fmtValue, maybeLink } from './format.js';
import { cls } from './classes.js';

const SEC = 'grid gap-1 border-t border-line pt-2';
const K = 'text-[11px] uppercase tracking-wider text-muted';
const SUB = 'text-xs text-muted [overflow-wrap:anywhere]';
import { colorOf, describe, PHASE_COLOR, phaseIndex } from './timeline.js';

const pathOf = (n) => { const out = []; for (let p = n.parent; p; p = p.parent) out.unshift(describe(p)); return out; };

const originHtml = (o) => !o || !o.file ? '' : `
  <div class="${SEC}"><div class="${K}">Origin</div>
    <div class="font-mono text-xs text-brass [overflow-wrap:anywhere]">${maybeLink(o.file + (o.line ? ':' + o.line : ''), o.link)}${o.vendor ? ' <span class="ml-1.5 rounded border border-line px-1 font-mono text-[10px] uppercase tracking-wider text-muted">vendor</span>' : ''}</div>
    ${o.call ? `<div class="${SUB} font-mono">${maybeLink(o.call, o.call_link)}</div>` : ''}
    ${o.frames?.length ? `<details><summary class="cursor-pointer text-xs text-muted">Call chain (${o.frames.length})</summary><ol class="mt-1 grid gap-0.5 pl-4.5 text-[11.5px] [overflow-wrap:anywhere] list-decimal">${o.frames.map((f) => `<li class="font-mono">${maybeLink((f.file ?? '') + (f.line ? ':' + f.line : ''), f.link)} ${maybeLink(f.call ?? '', f.call_link)}</li>`).join('')}</ol></details>` : ''}
  </div>`;

function metaRows(meta) {
  const links = meta._links ?? {};
  return Object.entries(meta)
    .filter(([k]) => k !== '_links')
    .map(([k, v]) => `<tr><th class="whitespace-nowrap py-0.5 pr-2.5 text-left align-top text-xs font-medium text-muted">${esc(k)}</th><td class="py-0.5 font-mono text-xs [overflow-wrap:anywhere]">${links[k] ? maybeLink(fmtValue(v), links[k]) : esc(fmtValue(v))}</td></tr>`)
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
    <div class="font-semibold [overflow-wrap:anywhere]">${esc(describe(n))}</div>
    ${path.length ? `<div class="text-[11.5px] text-muted [overflow-wrap:anywhere]">${path.map(esc).join(' <span class="text-brass">›</span> ')}</div>` : ''}
    <div class="grid grid-cols-[repeat(auto-fill,minmax(80px,1fr))] gap-2">
      <div class="grid gap-0.5"><span class="${K}">Duration</span><b class="tabular-nums">${ms(dur)}</b></div>
      <div class="grid gap-0.5"><span class="${K}">Starts at</span><b class="tabular-nums">${ms(n.s)}</b></div>
      <div class="grid gap-0.5"><span class="${K}">Of total</span><b class="tabular-nums">${((dur / Math.max(model.duration, 0.001)) * 100).toFixed(1)} %</b></div>
      ${n.children.length ? `<div class="grid gap-0.5"><span class="${K}">Children</span><b class="tabular-nums">${n.children.length}</b></div>` : ''}
    </div>
    ${d.sql ? `<div class="${SEC}"><div class="${K}">Query</div><pre class="m-0 whitespace-pre-wrap rounded-md bg-sunk p-2 font-mono text-xs [overflow-wrap:anywhere]">${esc(d.sql)}</pre></div>` : ''}
    ${rows ? `<div class="${SEC}"><table class="w-full border-collapse">${rows}</table></div>` : ''}
    ${n.redis?.length ? `<div class="${SEC}"><div class="${K}">Redis commands</div>${n.redis.map((r) => `<div class="${SUB} font-mono">${esc(r.name)} <span class="tabular-nums">${ms(r.e - r.s)}</span></div>`).join('')}</div>` : ''}
    ${originHtml(d.origin)}`;
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
  document.body.append(pop);
  let target = null;

  const open = (el, html, clientX) => {
    pop.innerHTML = html;
    pop.hidden = false;
    target = el;
    const r = el.getBoundingClientRect();
    const w = pop.offsetWidth;
    const h = pop.offsetHeight;
    const x = clientX ?? r.left + Math.min(r.width, 40) / 2;
    const left = Math.max(16, Math.min(x - 28, innerWidth - w - 16));
    const below = r.bottom + 10 + h <= innerHeight - 8 || r.top - 10 - h < 8;
    pop.style.left = left + 'px';
    pop.style.top = (below ? r.bottom + 10 : r.top - h - 10) + 'px';
    pop.dataset.side = below ? 'below' : 'above';
    pop.style.setProperty('--arrow', Math.max(14, Math.min(x - left, w - 14)) + 'px');
    pop.querySelector('[data-pop-close]')?.focus({ preventScroll: true });
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
  document.addEventListener('scroll', (e) => { if (!pop.contains(e.target)) close(); }, true);

  return { close };
}
