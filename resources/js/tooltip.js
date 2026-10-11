/**
 * One tooltip for every element with a data-tip attribute, shown on hover
 * and keyboard focus, placed above the element (below when there is no room).
 */
export function setupTooltip() {
  const tip = document.createElement('div');
  tip.className = 'pointer-events-none fixed z-[60] max-w-[min(520px,calc(100vw-24px))] rounded-md border border-line bg-panel px-2 py-1 font-mono text-[11.5px] text-fg shadow-[0_6px_18px_rgba(0,0,0,.18)] [overflow-wrap:anywhere]';
  tip.setAttribute('role', 'tooltip');
  tip.id = 'monocle-tooltip';
  tip.hidden = true;
  document.body.append(tip);

  let target = null;
  let timer = null;
  let described = null;

  const place = () => {
    const r = target.getBoundingClientRect();
    const w = tip.offsetWidth;
    const h = tip.offsetHeight;
    const left = Math.max(12, Math.min(r.left + r.width / 2 - w / 2, innerWidth - w - 12));
    const top = r.top - h - 6 >= 8 ? r.top - h - 6 : r.bottom + 6;
    tip.style.left = left + 'px';
    tip.style.top = top + 'px';
  };

  const show = (el) => {
    clearTimeout(timer);
    target = el;
    timer = setTimeout(() => {
      if (target !== el || !el.isConnected) return;
      tip.textContent = el.dataset.tip;
      tip.hidden = false;
      if (!el.hasAttribute('aria-describedby')) {
        el.setAttribute('aria-describedby', tip.id);
        described = el;
      }
      place();
    }, 250);
  };

  const hide = () => {
    clearTimeout(timer);
    described?.removeAttribute('aria-describedby');
    described = null;
    target = null;
    tip.hidden = true;
  };

  document.addEventListener('mouseover', (e) => {
    const el = e.target.closest?.('[data-tip]:not([data-tip=""])');
    if (el && el !== target) show(el);
    if (!el && target) hide();
  });
  document.addEventListener('focusin', (e) => {
    const el = e.target.closest?.('[data-tip]:not([data-tip=""])');
    el ? show(el) : hide();
  });
  document.addEventListener('focusout', hide);
  document.addEventListener('scroll', hide, true);
  addEventListener('blur', hide);
}
