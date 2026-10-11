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
  .replace(/(?<![\w.\/-])\/(?:[\w.-]+\/){2,}([\w.-]+\/[\w.-]+(?::\d+)?)/g, (full, tail) => shortened(full, tail))
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

const COPY_ICON = '<svg class="size-3.5" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5.5" y="5.5" width="8" height="8" rx="1.5"/><path d="M10.5 5.5v-2a1.5 1.5 0 0 0-1.5-1.5H4A1.5 1.5 0 0 0 2.5 3.5V9A1.5 1.5 0 0 0 4 10.5h1.5"/></svg>';

/**
 * A button copying `text`. With hover: true it only shows while its `group/copy` parent is
 * hovered (or the button has keyboard focus); devices without hover always show it.
 */
export const copyButton = (text, what = 'value', { hover = false } = {}) => `<button type="button" class="inline-flex shrink-0 cursor-pointer rounded p-0.5 align-[-3px] text-muted hover:text-brass focus-visible:text-brass${hover ? ' [@media(hover:hover)]:opacity-0 group-hover/copy:opacity-100 focus-visible:opacity-100' : ''}" data-copy="${esc(text)}" aria-label="Copy ${esc(what)}" data-tip="Copy">${COPY_ICON}</button>`;

/** Copy the text of a copy button and confirm on the button for a moment. */
export function copyFrom(button) {
  const done = () => {
    button.dataset.tip = 'Copied';
    button.classList.add('!text-ok');
    setTimeout(() => { button.dataset.tip = 'Copy'; button.classList.remove('!text-ok'); }, 1200);
  };
  const fallback = () => {
    const area = Object.assign(document.createElement('textarea'), { value: button.dataset.copy });
    document.body.append(area);
    area.select();
    document.execCommand('copy');
    area.remove();
    done();
  };
  navigator.clipboard ? navigator.clipboard.writeText(button.dataset.copy).then(done, fallback) : fallback();
}

// Registered before anything else, so a copy click never also opens or toggles the row it sits in.
document.addEventListener('click', (e) => {
  const button = e.target.closest?.('[data-copy]');
  if (!button) return;
  e.preventDefault();
  e.stopImmediatePropagation();
  copyFrom(button);
}, true);
