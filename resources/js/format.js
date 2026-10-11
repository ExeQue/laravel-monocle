export const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

export const ms = (n) => {
  const v = Number(n ?? 0);
  if (v > 0 && v < 0.05) return '<0.1 ms';
  return v >= 1000 ? (v / 1000).toFixed(2) + ' s' : v.toFixed(1) + ' ms';
};

export const ago = (startedAt) => {
  const at = typeof startedAt === 'string' ? Date.parse(startedAt) / 1000 : Number(startedAt);
  const s = Math.max(0, Date.now() / 1000 - at);
  if (s < 60) return Math.round(s) + 's ago';
  if (s < 3600) return Math.round(s / 60) + 'm ago';
  if (s < 86400) return Math.round(s / 3600) + 'h ago';
  return Math.round(s / 86400) + 'd ago';
};

const ACRONYMS = new Set(['id', 'ttl', 'url', 'uri', 'ip', 'sql', 'html', 'json', 'mb', 'kb', 'ms', 'ai', 'mcp', 'http']);

export const humanize = (key) => String(key).split('_').map((w) => (ACRONYMS.has(w.toLowerCase()) ? w.toUpperCase() : w)).join(' ').replace(/^./, (c) => c.toUpperCase());

const shortened = (full, short) => `<span class="cursor-help" data-tip="${full}"><span aria-hidden="true">${short}</span><span class="sr-only select-none">${full}</span></span>`;

/**
 * Escaped text with every namespaced class name shortened to its basename,
 * every path to a class file (app/Models/User.php:12) to the class and line
 * (User:12), view paths to the part after resources/views and compiled views
 * to a short hash; the full name shows in a tooltip.
 */
export const classes = (text) => esc(text)
  .replace(/(?<![\w/])storage\/framework\/views\/([0-9a-f]{8})[0-9a-f]*\.(?:blade\.)?php/g, (full, hash) => shortened(full, `compiled/${hash}`))
  .replace(/(?<![\w/])resources\/views\/([\w./-]+)/g, (full, rest) => shortened(full, rest))
  .replace(/(?<![\w/])(?:[\w.-]+\/)+([A-Z]\w*)\.php(:\d+)?(?![\w/])/g, (full, base, line = '') => shortened(full, base + line))
  .replace(/\\?(?:[A-Za-z_]\w*\\)+([A-Za-z_]\w*)(?:(-&gt;|::)(?!class\b)(\w+)(?![\w(]))?/g, (full, base, arrow, method) => {
    const name = shortened(full.replace(/^\\/, '').replace(/(-&gt;|::)\w+$/, ''), base);

    return arrow ? `${name}${arrow}${method}()` : name;
  });

/** A link when the editor gave us one, otherwise plain text. */
export const maybeLink = (text, link, extra = '') => (link
  ? `<a class="underline decoration-brass underline-offset-2 hover:text-brass ${extra}" href="${esc(link)}">${classes(text)}</a>`
  : `<span class="${extra}">${classes(text)}</span>`);

export const fmtValue = (v) => {
  if (v === null || v === undefined) return '';
  if (Array.isArray(v) && v.length && v.every((x) => x !== null && typeof x !== 'object')) return v.join(', ');
  if (typeof v === 'object') return JSON.stringify(v);
  return String(v);
};
