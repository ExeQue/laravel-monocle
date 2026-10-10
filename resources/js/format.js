export const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

export const ms = (n) => {
  const v = Number(n ?? 0);
  return v >= 1000 ? (v / 1000).toFixed(2) + ' s' : v.toFixed(1) + ' ms';
};

export const statusClass = (s) => {
  if (s === null || s === undefined) return '';
  if (/^\d+$/.test(String(s))) return Number(s) >= 500 ? 'bad' : Number(s) >= 400 ? 'warn' : 'ok';
  return ['failed', 'error'].includes(String(s)) ? 'bad' : 'ok';
};

export const ago = (startedAt) => {
  const at = typeof startedAt === 'string' ? Date.parse(startedAt) / 1000 : Number(startedAt);
  const s = Math.max(0, Date.now() / 1000 - at);
  if (s < 60) return Math.round(s) + 's ago';
  if (s < 3600) return Math.round(s / 60) + 'm ago';
  if (s < 86400) return Math.round(s / 3600) + 'h ago';
  return Math.round(s / 86400) + 'd ago';
};

export const humanize = (key) => String(key).replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());

/** A link when the editor gave us one, otherwise plain text. */
export const maybeLink = (text, link, cls = '') => (link
  ? `<a class="editor-link ${cls}" href="${esc(link)}">${esc(text)}</a>`
  : `<span class="${cls}">${esc(text)}</span>`);

export const fmtValue = (v) => {
  if (v === null || v === undefined) return '';
  if (typeof v === 'object') return JSON.stringify(v);
  return String(v);
};
