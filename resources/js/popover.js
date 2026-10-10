import { esc, ms, fmtValue, maybeLink } from './format.js';
import { colorOf, describe, PHASE_COLOR, phaseIndex } from './timeline.js';

const pathOf = (n) => { const out = []; for (let p = n.parent; p; p = p.parent) out.unshift(describe(p)); return out; };

const originHtml = (o) => !o || !o.file ? '' : `
  <div class="pop-sec"><div class="pop-k">Origin</div>
    <div class="mono origin-line">${maybeLink(o.file + (o.line ? ':' + o.line : ''), o.link)}${o.vendor ? ' <span class="vendor-tag">vendor</span>' : ''}</div>
    ${o.call ? `<div class="pop-sub mono">${maybeLink(o.call, o.call_link)}</div>` : ''}
    ${o.frames?.length ? `<details><summary>Call chain (${o.frames.length})</summary><ol class="frames">${o.frames.map((f) => `<li class="mono">${maybeLink((f.file ?? '') + (f.line ? ':' + f.line : ''), f.link)} ${maybeLink(f.call ?? '', f.call_link)}</li>`).join('')}</ol></details>` : ''}
  </div>`;

function metaRows(meta) {
  const links = meta._links ?? {};
  return Object.entries(meta)
    .filter(([k]) => k !== '_links')
    .map(([k, v]) => `<tr><th>${esc(k)}</th><td class="mono">${links[k] ? maybeLink(fmtValue(v), links[k]) : esc(fmtValue(v))}</td></tr>`)
    .join('');
}

function nodeDetails(model, n) {
  const d = n.d ?? {};
  const dur = n.e - n.s;
  const meta = d.meta ?? {};
  const path = pathOf(n);
  const rows = metaRows(meta);
  return `
    <div class="pop-head"><i class="sw" style="background:${colorOf(n)}"></i><span class="type">${esc(n.kind)}</span><button class="pop-x" data-pop-close aria-label="Close">×</button></div>
    <div class="pop-title">${esc(describe(n))}</div>
    ${path.length ? `<div class="pop-path">${path.map(esc).join(' <span>›</span> ')}</div>` : ''}
    <div class="pop-facts">
      <div><span class="pop-k">Duration</span><b class="num">${ms(dur)}</b></div>
      <div><span class="pop-k">Starts at</span><b class="num">${ms(n.s)}</b></div>
      <div><span class="pop-k">Of total</span><b class="num">${((dur / Math.max(model.duration, 0.001)) * 100).toFixed(1)} %</b></div>
      ${n.children.length ? `<div><span class="pop-k">Children</span><b class="num">${n.children.length}</b></div>` : ''}
    </div>
    ${d.sql ? `<div class="pop-sec"><div class="pop-k">Query</div><pre class="pop-sql">${esc(d.sql)}</pre></div>` : ''}
    ${rows ? `<div class="pop-sec"><table class="pop-meta">${rows}</table></div>` : ''}
    ${n.redis?.length ? `<div class="pop-sec"><div class="pop-k">Redis commands</div>${n.redis.map((r) => `<div class="mono pop-sub">${esc(r.name)} <span class="num">${ms(r.e - r.s)}</span></div>`).join('')}</div>` : ''}
    ${originHtml(d.origin)}`;
}

function phaseDetails(model, p) {
  const slowest = [...p.members].sort((a, b) => (b.e - b.s) - (a.e - a.s)).slice(0, 5);
  const dur = p.e - p.s;
  return `
    <div class="pop-head"><i class="sw" style="background:${PHASE_COLOR[p.label] ?? 'var(--c-phase)'}"></i><span class="type">phase</span><button class="pop-x" data-pop-close aria-label="Close">×</button></div>
    <div class="pop-title">${esc(p.label)}</div>
    <div class="pop-facts">
      <div><span class="pop-k">Duration</span><b class="num">${ms(dur)}</b></div>
      <div><span class="pop-k">From</span><b class="num">${ms(p.s)}</b></div>
      <div><span class="pop-k">Of total</span><b class="num">${((dur / Math.max(model.duration, 0.001)) * 100).toFixed(1)} %</b></div>
      <div><span class="pop-k">Spans</span><b class="num">${p.members.length}</b></div>
    </div>
    ${slowest.length ? `<div class="pop-sec"><div class="pop-k">Slowest</div>${slowest.map((m) => `<button class="pop-row" data-pop-node="${m.id}"><span>${esc(describe(m))}</span><span class="num">${ms(m.e - m.s)}</span></button>`).join('')}</div>` : ''}`;
}

/**
 * Click a timeline row, block or phase bar to open its details next to the
 * click. getModel() returns the timeline model of the open capture.
 */
export function setupPopover(root, getModel) {
  const pop = document.createElement('div');
  pop.className = 'pop';
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
