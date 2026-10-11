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

/** A capture type in three letters, so the list's type column has one width. */
export const shortType = (type) => ({ request: 'req', command: 'cmd', job: 'job', mcp: 'mcp' })[type] ?? String(type ?? '').slice(0, 3);

export const humanize = (key) => String(key).split('_').map((w) => (ACRONYMS.has(w.toLowerCase()) ? w.toUpperCase() : w)).join(' ').replace(/^./, (c) => c.toUpperCase());

/** Where the application lives on the server, so paths can be shown relative to it. */
const paths = { base: '' };

export const usePathMappings = ({ base = '' } = {}) => Object.assign(paths, { base });

/** A server path relative to base_path() (app/Models/User.php:12); paths outside it stay as they are. */
export const projectPath = (path) => {
  const base = paths.base.replace(/\/$/, '');
  return base && path.startsWith(base + '/') ? path.slice(base.length + 1) : path;
};

const looksLikePath = (text) => /^\/?(?:[\w.-]+\/)+[\w.-]+(?::\d+)?$/.test(text);

const shortened = (full, short) => `<span class="cursor-help" data-tip="${looksLikePath(full) ? projectPath(full) : full}"><span aria-hidden="true">${short}</span><span class="sr-only select-none">${full}</span></span>`;

/**
 * Escaped text with every namespaced class name shortened to its basename,
 * every path to a class file (app/Models/User.php:12) to the class and line
 * (User:12), view paths to the part after resources/views and compiled views
 * to a short hash; the full name shows in a tooltip.
 */
export const classes = (text) => {
  // Each replacement is parked behind a placeholder, so a later pattern never matches inside
  // the markup (or the tooltip path) an earlier one produced.
  const parked = [];
  const park = (html) => `\u0000${parked.push(html) - 1}\u0000`;
  const out = esc(text)
    .replace(/(?<![\w.\/-])\/(?:[\w.-]+\/){2,}([\w.-]+\/[\w.-]+(?::\d+)?)/g, (full, tail) => park(shortened(full, classFile(tail) ?? tail)))
    .replace(/(?<![\w/])storage\/framework\/views\/([0-9a-f]{8})[0-9a-f]*\.(?:blade\.)?php/g, (full, hash) => park(shortened(full, `compiled/${hash}`)))
    .replace(/(?<![\w/])resources\/views\/([\w./-]+)/g, (full, rest) => park(shortened(full, rest)))
    .replace(/(?<![\w/])(?:[\w.-]+\/)+([A-Z]\w*)\.php(:\d+)?(?![\w/])/g, (full, base, line = '') => park(shortened(full, base + line)))
    .replace(/\\?(?:[A-Za-z_]\w*\\)+([A-Za-z_]\w*)(?:(-&gt;|::)(?!class\b)(\w+)(?![\w(]))?/g, (full, base, arrow, method) => {
      const name = shortened(full.replace(/^\\/, '').replace(/(-&gt;|::)\w+$/, ''), base);

      return park(arrow ? `${name}${arrow}${method}()` : name);
    });

  return out.replace(/\u0000(\d+)\u0000/g, (m, i) => parked[Number(i)]);
};

/** "Filesystem/FilesystemAdapter.php:459" as "FilesystemAdapter:459" when it names a class file. */
const classFile = (tail) => {
  const match = /(?:^|\/)([A-Z]\w*)\.php(:\d+)?$/.exec(tail);
  return match ? match[1] + (match[2] ?? '') : null;
};

/** A link when the editor gave us one, otherwise plain text. */
export const maybeLink = (text, link, extra = '') => (link
  ? `<a class="decoration-brass underline-offset-2 hover:text-brass hover:underline ${extra}" href="${esc(link)}">${classes(text)}</a>`
  : `<span class="${extra}">${classes(text)}</span>`);

/** Some HTML with a copy button that shows on hover; the button copies `text`, the full name or path. */
export const copyable = (html, text, what = /(\.php|:\d+)$/.test(text) ? 'path' : 'name') => (text
  ? `<span class="inline-flex max-w-full items-start gap-1"><span class="min-w-0">${html}</span>${copyButton(projectPath(text), what)}</span>`
  : html);

/** A class name or path, linked to the editor when there is a link, with a copy button. */
export const nameLink = (text, link, extra = '') => copyable(maybeLink(text, link, extra), text);

export const fmtValue = (v) => {
  if (v === null || v === undefined) return '';
  if (Array.isArray(v) && v.length && v.every((x) => x !== null && typeof x !== 'object')) return v.join(', ');
  if (typeof v === 'object') return JSON.stringify(v);
  return String(v);
};

const COPY_ICON = '<svg class="size-3.5" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5.5" y="5.5" width="8" height="8" rx="1.5"/><path d="M10.5 5.5V3.5A1.5 1.5 0 0 0 9 2H3.5A1.5 1.5 0 0 0 2 3.5V9a1.5 1.5 0 0 0 1.5 1.5h2"/></svg>';
const CHECK_ICON = '<svg class="size-3.5 text-ok" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 8.5 6.5 12 13 4.5"/></svg>';

/** A button copying `text`, styled like the capture id's copy button in the header. */
export const copyButton = (text, what = 'value') => `<button type="button" class="inline-flex shrink-0 cursor-pointer rounded p-0.5 align-[-3px] text-muted hover:text-brass" data-copy="${esc(text)}" aria-label="Copy ${esc(what)}" data-tip="Copy ${esc(what)}">${COPY_ICON}</button>`;

/** Copy the text of a copy button and show a checkmark on it for a moment, like the header does. */
export function copyFrom(button) {
  const done = () => {
    const { tip, label } = button.dataset.copyRestore ? JSON.parse(button.dataset.copyRestore) : { tip: button.dataset.tip, label: button.getAttribute('aria-label') };
    button.dataset.copyRestore = JSON.stringify({ tip, label });
    button.innerHTML = CHECK_ICON;
    button.dataset.tip = 'Copied';
    button.setAttribute('aria-label', 'Copied');
    button.classList.add('!opacity-100');
    clearTimeout(button.copyTimer);
    button.copyTimer = setTimeout(() => {
      button.innerHTML = COPY_ICON;
      button.dataset.tip = tip;
      button.setAttribute('aria-label', label);
      button.classList.remove('!opacity-100');
      delete button.dataset.copyRestore;
    }, 1500);
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

/** Is this a recorded throwable ({class, message, previous?})? */
export const isThrowable = (v) => !!v && typeof v === 'object' && !Array.isArray(v) && typeof v.class === 'string' && 'message' in v;

/**
 * A recorded throwable and the exceptions it wraps. Compact (tables): one line each,
 * `Basename: message`, the full class name in a tooltip. Full (details): the full class
 * name on a line of its own with the message under it, wrapped exceptions indented.
 */
export const exceptionHtml = (e, { limit = null, full = false } = {}) => {
  const chain = [e, ...(Array.isArray(e.previous) ? e.previous : e.previous ? [e.previous] : [])];
  return full ? chainBlocks(chain) : previousHtml(chain, limit, 0);
};

const chainBlocks = (chain) => `<div class="grid gap-2">${chain.map((x, i) => `<div class="grid gap-0.5 ${i ? 'border-l-2 border-line pl-3' : ''}" style="margin-left:${Math.min(i, 4) * 12}px"><span class="flex items-start gap-1.5">${x.link ? `<a class="font-mono decoration-brass underline-offset-2 hover:text-brass hover:underline ${i ? 'text-muted' : 'text-bad'} [overflow-wrap:anywhere]" href="${esc(x.link)}">${esc(x.class)}</a>` : `<span class="font-mono ${i ? 'text-muted' : 'text-bad'} [overflow-wrap:anywhere]">${esc(x.class)}</span>`}${copyButton(`${x.class}: ${x.message ?? ''}`, i ? `wrapped exception ${i}` : 'exception')}</span><span class="[overflow-wrap:anywhere]">${esc(String(x.message ?? ''))}</span>${x.file ? `<span class="font-mono text-[11px] text-muted">${maybeLink(x.file, x.file_link)}</span>` : ''}</div>`).join('')}</div>`;

/** Throwables as compact lines; from `nested` on they are indented under the first. */
export const previousHtml = (chain, limit = null, nested = 1) => {
  const text = (m) => { const s = String(m ?? ''); return limit && s.length > limit ? s.slice(0, limit) + '…' : s; };
  return `<div class="grid gap-0.5">${chain.map((x, j) => ({ x, i: j + nested })).map(({ x, i }) => `<div class="${i ? 'pl-3 text-muted' : ''} [overflow-wrap:anywhere]">${i ? '<span aria-hidden="true">↳ </span>' : ''}${maybeLink(x.class, x.link, `font-mono ${i ? '' : 'text-bad'}`)}: ${esc(text(x.message))}</div>`).join('')}</div>`;
};
