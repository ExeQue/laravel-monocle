/**
 * Lets a mouse drag scroll strips that only scroll sideways, like the tab bar, as a finger
 * does on a touch screen. A press that turns into a drag does not count as a click.
 */
export function setupDragScroll(selector) {
  document.addEventListener('pointerdown', (down) => {
    const strip = down.target.closest?.(selector);
    if (!strip || down.pointerType !== 'mouse' || down.button !== 0) return;

    const startX = down.clientX;
    const startLeft = strip.scrollLeft;
    let dragging = false;

    const move = (event) => {
      const dx = event.clientX - startX;
      // A few pixels of wobble is still a click.
      if (!dragging && Math.abs(dx) < 5) return;
      dragging = true;
      // Snapping fights a scroll that follows the mouse; it comes back on release.
      strip.style.scrollSnapType = 'none';
      strip.scrollLeft = startLeft - dx;
    };

    const swallowClick = (event) => {
      event.preventDefault();
      event.stopPropagation();
    };

    const up = () => {
      removeEventListener('pointermove', move);
      removeEventListener('pointerup', up);
      strip.style.scrollSnapType = '';
      if (!dragging) return;
      // The click that ends a drag comes right after pointerup; nothing later is swallowed.
      addEventListener('click', swallowClick, { capture: true, once: true });
      setTimeout(() => removeEventListener('click', swallowClick, { capture: true }));
    };

    addEventListener('pointermove', move);
    addEventListener('pointerup', up);
  });
}
