import { esc, humanize, fmtValue, maybeLink } from '../format.js';
import { totalValue } from './totals.js';

/** Field changes as a table: a new field is null in From, a removed one null in To. */
export const changesTable = (changes) => {
  const td = 'px-1.5 py-0.5 align-top font-mono [overflow-wrap:anywhere]';
  const value = (v) => esc(fmtValue(v));
  return `<table class="w-full table-fixed border-collapse text-xs"><colgroup><col class="w-[28%]"><col class="w-[36%]"><col class="w-[36%]"></colgroup><thead><tr>${['Field', 'From', 'To'].map((h) => `<th scope="col" class="px-1.5 py-0.5 text-left font-medium text-muted">${h}</th>`).join('')}</tr></thead><tbody>${changes.map((c) => `<tr class="border-t border-line"><td class="${td}">${esc(c.field)}</td><td class="${td} text-bad">${c.from === null ? '<span class="text-muted">null</span>' : value(c.from)}</td><td class="${td} text-ok">${c.to === null ? '<span class="text-muted">null</span>' : value(c.to)}</td></tr>`).join('')}</tbody></table>`;
};

/** Nested input, cookies and session data as dot.notated key/value pairs. */
export function dot(value, prefix = '') {
  if (value === null || typeof value !== 'object') return prefix ? [[prefix, value]] : [];
  const entries = Array.isArray(value) ? value.map((v, i) => [String(i), v]) : Object.entries(value);
  if (!entries.length) return prefix ? [[prefix, Array.isArray(value) ? '[]' : '{}']] : [];
  return entries.flatMap(([k, v]) => dot(v, prefix ? `${prefix}.${k}` : k));
}

/** A payload value; JSON strings (Livewire snapshots, API bodies) are shown formatted. */
export function payloadValue(v) {
  if (v === null || (Array.isArray(v) && !v.length)) return `<span class="text-muted">${v === null ? 'null' : '[]'}</span>`;
  if (typeof v === 'string' && /^\s*[[{]/.test(v)) {
    try { return `<pre class="m-0 max-h-64 overflow-auto whitespace-pre-wrap break-all">${esc(JSON.stringify(JSON.parse(v), null, 2))}</pre>`; } catch { /* not JSON */ }
  }
  return esc(fmtValue(v));
}

export function headers(headers) {
  return Object.entries(headers ?? {}).map(([name, values]) => [name, Array.isArray(values) ? values.join(', ') : fmtValue(values)]);
}

export function body(text, type) {
  if (typeof text !== 'string') return '';
  if (/json/i.test(type ?? '')) { try { return JSON.stringify(JSON.parse(text), null, 2); } catch { /* not valid JSON */ } }
  return text;
}

/** A value as text to copy: objects as JSON. */
export function copyText(value) {
  return value !== null && typeof value === 'object' ? JSON.stringify(value) : String(value ?? '');
}

/** A name cut to 32 characters, for header and cookie names; the full name is in a tooltip. */
export function shortName(name) {
  return name.length > 32 ? name.slice(0, 32) + '…' : name;
}

/** A dot-notated key with its parents toned down, so the field itself stands out. */
export function dottedKey(key) {
  const at = String(key).lastIndexOf('.');
  return at < 0 ? esc(key) : `<span class="text-muted">${esc(key.slice(0, at + 1))}</span>${esc(key.slice(at + 1))}`;
}

/** A url with its query string decoded for reading; copying keeps it as sent. */
export function readableUrl(url) {
  try { return decodeURI(String(url ?? '')).replace(/%5B/gi, '[').replace(/%5D/gi, ']'); } catch { return url; }
}

export function levelClass(level) {
  return { debug: 'text-muted', info: 'text-[var(--c-http)]', notice: 'text-[var(--c-http)]', warning: 'text-warn', error: 'text-bad', critical: 'text-bad', alert: 'text-bad', emergency: 'text-bad' }[level] ?? '';
}

/** One item of a response capture card. */
export function cardValue(value, key) {
  if (Array.isArray(value) && value.length && value.every((c) => c && typeof c === 'object' && 'field' in c && 'from' in c && 'to' in c)) return changesTable(value);
  if (key.endsWith('_bytes') && typeof value === 'number') return esc(totalValue([key, value, 'bytes']));
  if (Array.isArray(value) && value.length && value.every((v) => v && typeof v === 'object' && !Array.isArray(v))) {
    const cols = [...new Set(value.flatMap((v) => Object.keys(v)))];
    const cell = (v) => (v === null || v === undefined ? '<span class="text-muted">-</span>' : esc(typeof v === 'object' ? JSON.stringify(v) : String(v)));
    return `<table class="w-full border-collapse text-xs"><thead><tr>${cols.map((c) => `<th scope="col" class="px-1.5 py-0.5 text-left font-medium text-muted">${esc(humanize(c))}</th>`).join('')}</tr></thead><tbody>${value.map((row) => `<tr class="border-t border-line">${cols.map((c) => `<td class="px-1.5 py-0.5 align-top [overflow-wrap:anywhere]">${cell(row[c])}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
  }
  if (key === 'body' && typeof value === 'string') return `<pre class="m-0 max-h-96 overflow-auto whitespace-pre-wrap">${esc(value)}</pre>`;
  if (Array.isArray(value) && value.every((v) => v === null || typeof v !== 'object')) return value.map((v) => `<div>${esc(fmtValue(v))}</div>`).join('');
  if (value && typeof value === 'object') return dot(value).map(([k, v]) => `<div><span class="text-muted">${esc(k)}</span> ${esc(fmtValue(v))}</div>`).join('');
  return esc(fmtValue(value));
}

/** Text with the given substrings turned into editor links. */
export function linkText(text, links = {}) {
  const tokens = Object.keys(links ?? {}).sort((a, b) => b.length - a.length);
  if (!tokens.length) return esc(text);
  const pattern = new RegExp(tokens.map((t) => t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|'), 'g');
  let out = '';
  let last = 0;
  for (const m of text.matchAll(pattern)) {
    out += esc(text.slice(last, m.index)) + maybeLink(m[0], links[m[0]]);
    last = m.index + m[0].length;
  }
  return out + esc(text.slice(last));
}

/** Response capture cards: a title, an optional subtitle and named items. */
export function cardsHtml(cards) {
  return `<div class="grid gap-3">${(cards ?? []).map((card) => `<div class="overflow-hidden rounded-md border border-line bg-panel">
    <div class="flex flex-wrap items-baseline gap-x-2 border-b border-line bg-sunk px-3 py-2"><b class="text-[13px]">${esc(card.title ?? '')}</b><span class="font-mono text-[11px] text-muted">${esc(card.subtitle ?? '')}</span></div>
    <dl class="m-0">${Object.entries(card.items ?? {}).map(([k, v]) => `<div class="grid gap-1 border-b border-line px-3 py-2 last:border-b-0 sm:grid-cols-[120px_minmax(0,1fr)] sm:gap-3"><dt class="text-xs text-muted">${esc(humanize(k))}</dt><dd class="m-0 min-w-0 font-mono text-xs [overflow-wrap:anywhere]">${cardValue(v, k)}</dd></div>`).join('') || '<p class="m-0 px-3 py-2 text-xs text-muted">Nothing happened in this component.</p>'}</dl>
  </div>`).join('')}</div>`;
}

/**
 * HTML rendered on the server (a lens's details view), finished like the rest of the dashboard:
 * class names and paths shortened and linked, capture cards drawn.
 */
export function hydrate(html) {
  const root = new DOMParser().parseFromString(`<div>${html}</div>`, 'text/html').body.firstElementChild;
  root.querySelectorAll('[data-monocle-name]').forEach((el) => {
    const text = el.textContent ?? '';
    const extra = el.dataset.class ?? '';
    // data-full keeps the whole name (details show full class names); otherwise it is shortened like everywhere else.
    el.outerHTML = 'full' in el.dataset
      ? (el.dataset.link ? `<a class="decoration-brass underline-offset-2 hover:text-brass hover:underline ${extra}" href="${esc(el.dataset.link)}">${esc(text)}</a>` : `<span class="${extra}">${esc(text)}</span>`)
      : maybeLink(text, el.dataset.link, extra);
  });
  root.querySelectorAll('[data-monocle-cards]').forEach((el) => {
    try { el.outerHTML = cardsHtml(JSON.parse(el.dataset.monocleCards)); } catch { /* not cards */ }
  });
  return root.innerHTML;
}
