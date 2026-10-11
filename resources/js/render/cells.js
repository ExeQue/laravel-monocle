import { esc, ms, humanize, fmtValue, maybeLink, classes, copyButton, isThrowable, exceptionHtml, previousHtml } from '../format.js';
import { cls, statusClass, httpStatusClass } from '../classes.js';
import { changesTable, levelClass } from './values.js';

/** Values long enough to need breaking anywhere, like keys, SQL and paths. */
export function longValue(value) {
  return typeof value === 'string' ? value.length > 24 : value !== null && typeof value === 'object';
}

export function columnsOf(rows, section = null, presentation = null) {
  const blank = (v) => v === null || v === undefined || v === '' || v === false || v === 0 || (Array.isArray(v) && v.length === 0);
  // A column that is empty, false or 0 in every row, or true in every row, says nothing.
  const speaks = (k) => rows.some((row) => !blank(row[k])) && !(rows.length > 1 && rows.every((row) => row[k] === true));
  // A lens that names its columns gets those, in its order.
  if (presentation?.columns?.length) return presentation.columns.filter((c) => rows.some((row) => c in row) && speaks(c)).concat(rows.some((row) => row.origin) ? ['origin'] : []);
  const detailOnly = [...(presentation?.details_only ?? []), 'n', '_links', 'frames'];
  const keys = [];
  for (const row of rows.slice(0, 20)) for (const k of Object.keys(row)) if (!keys.includes(k) && !detailOnly.includes(k)) keys.push(k);
  // Ids belong in the details.
  let used = keys.filter((k) => !k.endsWith('_id') && speaks(k));
  if (used.includes('duration')) used = used.filter((k) => k !== 'time');
  // Duration and origin always make it in, at the end; the rest is capped to keep the table readable.
  const tail = ['duration', 'origin'].filter((k) => used.includes(k));
  return used.filter((k) => !tail.includes(k)).slice(0, 8 - (tail.includes('duration') ? 1 : 0)).concat(tail);
}

/** A column header: `time` is an offset into the capture, not a duration. */
export function columnLabel(col, presentation = null) {
  if (presentation?.labels?.[col]) return presentation.labels[col];
  if (col === 'time') return 'Started at';
  if (col.endsWith('_bytes') && !col.includes('size')) return humanize(col.replace(/_bytes$/, '')) + ' size';
  return humanize(col.replace(/_(bytes|ms)$/, ''));
}

/** Columns that only hold short words or codes take as little width as they can. */
export function narrow(key, rows = []) {
  if (key === 'origin') return true;
  const values = rows.map((row) => row[key]).filter((v) => v !== null && v !== undefined && v !== '');
  return values.length > 0 && values.every((v) => (typeof v === 'number' || typeof v === 'string') && String(v).length <= 7);
}

/** Columns of numbers (and durations, sizes, counts) line up on the right. */
export function numeric(key, rows = []) {
  if (['duration', 'time', 'n'].includes(key) || /_(ms|bytes|count)$/.test(key)) return true;
  const values = rows.map((row) => row[key]).filter((v) => v !== null && v !== undefined);
  return values.length > 0 && values.every((v) => typeof v === 'number');
}

/** The tint of a row whose lens highlights one of its fields. */
export function rowTone(row, presentation = null) {
  const tone = Object.entries(presentation?.highlights ?? {}).find(([field]) => row[field])?.[1];
  return tone ? `bg-${tone}-soft/60` : '';
}

const TONES = { ok: 'text-ok', warn: 'text-warn', bad: 'text-bad', muted: 'text-muted' };

/** How a lens can ask a field to show: Presentation::format(). */
const FORMATS = {
  'http-status': (v) => `<span class="font-medium tabular-nums ${httpStatusClass(v)}">${v == null ? 'failed' : esc(v)}</span>`,
  status: (v) => `<span class="${statusClass(v)}">${esc(v)}</span>`,
  level: (v) => `<span class="font-medium ${levelClass(v)}">${esc(v)}</span>`,
  flag: (v, row, yes = 'yes', no = 'no', tone = 'bad', yesTone = 'ok') => (typeof v !== 'boolean' ? esc(fmtValue(v)) : v ? (yes ? `<span class="${TONES[yesTone] ?? ''}">${esc(yes)}</span>` : '') : no ? `<span class="font-medium ${TONES[tone] ?? ''}">${esc(no)}</span>` : ''),
  bool: (v) => `<span class="font-mono ${v ? 'text-ok' : 'text-muted'}">${esc(String(v))}</span>`,
  prefix: (v, row, prefix = '') => (v === null || v === undefined || v === '' ? '' : `<span class="font-mono">${esc(prefix)}${esc(v)}</span>`),
  reference: (v, row, text = '#') => (v ? `<span class="rounded bg-warn-soft px-1.5 font-mono text-[10.5px] text-warn">${esc(text)}${esc(v)}</span>` : ''),
  highlight: (v, row, values = [], tone = 'warn') => (values.includes(v) ? `<span class="font-medium ${TONES[tone] ?? ''}">${esc(v)}</span>` : esc(fmtValue(v))),
  fields: (v) => (v && typeof v === 'object' && !Array.isArray(v) && !isThrowable(v)
    ? Object.entries(v).map(([k, x]) => `<div><span class="text-muted">${esc(k)}</span> <span class="font-mono">${classes(fmtValue(x).slice(0, 120))}</span></div>`).join('')
    : esc(fmtValue(v))),
  rules: (v, row) => (v && typeof v === 'object' && !Array.isArray(v)
    ? Object.entries(v).map(([field, rules]) => `<div><span class="font-mono">${esc(field)}</span> <span class="text-muted">${(Array.isArray(rules) ? rules.map(fmtValue) : String(rules).split('|')).map((r) => maybeLink(r, row._links?.rules?.[r])).join(' | ')}</span></div>`).join('')
    : esc(fmtValue(v))),
};

export function cell(row, key, presentation = null) {
  const v = row[key];
  if (key === 'origin') return v?.file ? maybeLink(v.file + (v.line ? ':' + v.line : ''), v.link, cls.origin) + (v.via ? `<span class="block font-mono text-[11px] text-muted">via ${maybeLink(v.via.call ?? v.via.file, v.via.call_link ?? v.via.link)}</span>` : '') : '';
  const format = presentation?.formats?.[key];
  if (format && FORMATS[format[0]]) return FORMATS[format[0]](v, row, ...format.slice(1));
  if (key === 'duration' && typeof v === 'number' && typeof row.time === 'number') return `<span class="whitespace-nowrap">${esc(ms(v))}</span><span class="block whitespace-nowrap text-[11px] text-muted">at ${esc(ms(row.time))}</span>`;
  if ((key === 'duration' || key === 'time' || key.endsWith('_ms')) && typeof v === 'number') return `<span class="whitespace-nowrap">${esc(ms(v))}</span>`;
  if ((key === 'bytes' || key.endsWith('_bytes') || key === 'size') && typeof v === 'number') return '<span class="whitespace-nowrap">' + esc(v >= 1048576 ? (v / 1048576).toFixed(1) + ' MB' : v >= 1024 ? (v / 1024).toFixed(1) + ' KB' : v + ' B') + '</span>';
  // File paths look like origins everywhere.
  if (typeof v === 'string' && /^[\w./-]+\.(php|blade\.php)(:\d+)?$/.test(v)) return `<span class="group/copy inline-flex max-w-full items-start gap-1"><span class="min-w-0">${maybeLink(v, row._links?.[key], 'font-mono text-[11.5px] text-brass')}</span>${copyButton(v, humanize(key).toLowerCase(), { hover: true })}</span>`;
  if (v && typeof v === 'object' && !Array.isArray(v) && !Object.keys(v).length) return '';
  if (Array.isArray(v) && !v.length) return '';
  // Long or multi-line text: the first line in the table, all of it in the details.
  if (typeof v === 'string' && (v.includes('\n') || v.length > 160)) return `<span data-tip="${esc(v.slice(0, 600))}">${esc(v.split('\n')[0].slice(0, 160))}…</span>`;
  if (isThrowable(v)) return exceptionHtml(v, { limit: 140 });
  if (Array.isArray(v) && v.length && v.every(isThrowable)) return previousHtml(v, 140);
  if (Array.isArray(v) && v.length && v.every((c) => c && typeof c === 'object' && 'field' in c && 'from' in c && 'to' in c)) return changesTable(v);
  if (Array.isArray(v) && v.length && v.every((c) => c && typeof c === 'object' && 'field' in c && 'rule' in c)) {
    return v.map((c) => `<div><span class="font-mono">${esc(c.field)}</span> <span class="text-warn">${esc(c.rule)}${c.parameters?.length ? esc('(' + c.parameters.join(', ') + ')') : ''}</span>${c.message ? ` <span class="text-muted">${esc(c.message)}</span>` : ''}</div>`).join('');
  }
  if (typeof v === 'boolean') return v ? 'yes' : '<span class="text-muted">no</span>';
  const text = fmtValue(v);
  const shown = text.length > 240 ? text.slice(0, 240) + '...' : text;
  const html = row._links?.[key] ? maybeLink(shown, row._links[key]) : classes(shown);
  // Words and names are worth copying; flags, counts and single characters are not.
  return typeof v === 'string' && text.length >= 4 ? `<span class="group/copy inline-flex max-w-full items-start gap-1"><span class="${longValue(v) ? 'min-w-0 [overflow-wrap:break-word]' : 'whitespace-nowrap'}">${html}</span>${copyButton(text, humanize(key).toLowerCase(), { hover: true })}</span>` : html;
}
