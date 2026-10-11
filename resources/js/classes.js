/** Tailwind class sets used in more than one place. */
export const cls = {
  chip: 'inline-flex items-center rounded-full border border-line px-2.5 py-0.5 text-xs text-muted aria-pressed:border-brass aria-pressed:bg-brass aria-pressed:text-on-brass',
  link: 'text-brass underline underline-offset-[3px]',
  editorLink: 'underline decoration-brass underline-offset-2 hover:text-brass',
  type: 'rounded bg-sunk px-1.5 py-px font-mono text-[10.5px] uppercase tracking-wider text-muted',
  swatch: 'inline-block size-2.5 shrink-0 rounded-sm',
  sectionTitle: 'mb-2.5 text-[13px] font-medium uppercase tracking-widest text-muted',
  empty: 'rounded-md border border-dashed border-line p-6 text-center text-muted',
  tableWrap: 'scroll-quiet overflow-x-auto rounded-md border border-line bg-panel',
  th: 'whitespace-nowrap border-b border-line bg-sunk px-3 py-2 text-left text-xs font-medium text-muted',
  td: 'border-b border-line px-3 py-2 align-top break-words [tr:last-child>&]:border-b-0',
  origin: 'block font-mono text-[11.5px] text-brass',
};

export const statusClass = (s) => {
  if (s === null || s === undefined) return '';
  if (/^\d+$/.test(String(s))) return Number(s) >= 500 ? 'text-bad' : Number(s) >= 400 ? 'text-warn' : 'text-ok';
  return ['failed', 'error'].includes(String(s)) ? 'text-bad' : 'text-ok';
};
