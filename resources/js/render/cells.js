import { esc, ms, humanize, fmtValue, maybeLink, classes, copyButton, isThrowable, exceptionHtml, previousHtml } from '../format.js';
import { cls, statusClass, httpStatusClass } from '../classes.js';
import { changesTable, levelClass } from './values.js';

/** Table columns whose values are worth copying: keys, urls, names, paths, messages. */
export const COPY_COLUMNS = ['key', 'keys', 'url', 'path', 'destination', 'view', 'name', 'class', 'message', 'command', 'job', 'event', 'listener', 'ability', 'subject', 'to', 'from', 'tool', 'model', 'feature', 'component', 'prompt', 'response', 'text', 'file', 'id', 'uuid', 'job_id', 'batch_id', 'value', 'sql'];

/** Values long enough to need breaking anywhere, like keys, SQL and paths. */
export function longValue(value) {
  return typeof value === 'string' ? value.length > 24 : value !== null && typeof value === 'object';
}

export function columnsOf(rows, section = null, presentation = null) {
  const keys = [];
  const detailOnly = [...(presentation?.details_only ?? []), 'n', '_links', 'frames', 'request_headers', 'response_headers', 'request_body', 'response_body', 'request_payload', 'response_capture', ...(section === 'http' ? ['exception', 'failed', 'timing'] : []), ...(section === 'validation' ? ['input'] : [])];
  // A lens that names its columns gets those, in its order.
  if (presentation?.columns?.length) return presentation.columns.filter((c) => rows.some((row) => c in row)).concat(rows.some((row) => row.origin) ? ['origin'] : []);
  for (const row of rows.slice(0, 20)) for (const k of Object.keys(row)) if (!keys.includes(k) && !detailOnly.includes(k)) keys.push(k);
  const blank = (v) => v === null || v === undefined || v === '' || (Array.isArray(v) && v.length === 0);
  // An exception code is nearly always 0; the column only shows when one is not.
  // A column that is false (or a code of 0) in every row says nothing; ids belong in the details.
  let used = keys.filter((k) => !k.endsWith('_id') && rows.some((row) => !blank(row[k]) && row[k] !== false && !(k === 'code' && row[k] === 0) && !(k === 'ok' && row[k] === true) && !(['chained', 'attempts', 'delay', 'retries', 'attachments'].includes(k) && row[k] === 0)));
  if (used.includes('duration')) used = used.filter((k) => k !== 'time');
  used = used.filter((k) => k !== 'rolled_back');
  // HTTP calls read as "GET 200 https://...".
  if (section === 'http' && used.includes('status')) used = ['method', 'status', ...used.filter((k) => k !== 'method' && k !== 'status')].filter((k) => used.includes(k));
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

/** Columns that only hold a short word or code and take as little width as they can. */
export function narrow(key) {
  return ['method', 'status', 'origin'].includes(key);
}

export function numeric(key) {
  return ['count', 'duration', 'time', 'size', 'bytes', 'n', 'attempts', 'status', 'ttl'].includes(key) || /_(ms|bytes|count)$/.test(key);
}

export function cell(row, key) {
  const v = row[key];
  if (key === 'origin') return v?.file ? maybeLink(v.file + (v.line ? ':' + v.line : ''), v.link, cls.origin) + (v.via ? `<span class="block font-mono text-[11px] text-muted">via ${maybeLink(v.via.call ?? v.via.file, v.via.call_link ?? v.via.link)}</span>` : '') : '';
  if (key === 'duration' && typeof v === 'number' && typeof row.time === 'number') return `<span class="whitespace-nowrap">${esc(ms(v))}</span><span class="block whitespace-nowrap text-[11px] text-muted">at ${esc(ms(row.time))}</span>`;
  if ((key === 'duration' || key === 'time' || key.endsWith('_ms')) && typeof v === 'number') return `<span class="whitespace-nowrap">${esc(ms(v))}</span>`;
  if ((key === 'bytes' || key.endsWith('_bytes') || key === 'size') && typeof v === 'number') return '<span class="whitespace-nowrap">' + esc(v >= 1048576 ? (v / 1048576).toFixed(1) + ' MB' : v >= 1024 ? (v / 1024).toFixed(1) + ' KB' : v + ' B') + '</span>';
  if (key === 'status' && 'url' in row) return `<span class="font-medium tabular-nums ${httpStatusClass(v)}">${v == null ? 'failed' : esc(v)}</span>`;
  // File paths look like origins everywhere.
  if (key === 'file' && typeof v === 'string' && /\.(php|blade\.php)(:\d+)?$/.test(v)) return `<span class="group/copy">${maybeLink(v, row._links?.file, 'font-mono text-[11.5px] text-brass')} ${copyButton(v, 'file', { hover: true })}</span>`;
  if (key === 'rules' && v && typeof v === 'object' && !Array.isArray(v)) return Object.entries(v).map(([field, rules]) => `<div><span class="font-mono">${esc(field)}</span> <span class="text-muted">${(Array.isArray(rules) ? rules.map(fmtValue) : String(rules).split('|')).map((r) => maybeLink(r, row._links?.rules?.[r])).join(' | ')}</span></div>`).join('');
  if (key === 'status' && typeof v === 'string') return `<span class="${statusClass(v)}">${esc(v)}</span>`;
  if (key === 'level' && typeof v === 'string') return `<span class="font-medium ${levelClass(v)}">${esc(v)}</span>`;
  if (v && typeof v === 'object' && !Array.isArray(v) && !Object.keys(v).length) return '';
  if (Array.isArray(v) && !v.length) return '';
  if (['context', 'payload', 'data', 'params', 'parameters'].includes(key) && v && typeof v === 'object' && !Array.isArray(v) && !isThrowable(v)) return Object.entries(v).map(([k, x]) => `<div><span class="text-muted">${esc(k)}</span> <span class="font-mono">${classes(fmtValue(x).slice(0, 120))}</span></div>`).join('');
  // Long or multi-line messages: the first line in the table, all of it in the details.
  if (key === 'message' && typeof v === 'string' && (v.includes('\n') || v.length > 160)) return `<span data-tip="${esc(v.slice(0, 600))}">${esc(v.split('\n')[0].slice(0, 160))}…</span>`;
  if (key === 'allowed' && typeof v === 'boolean') return v ? '<span class="text-ok">allowed</span>' : '<span class="font-medium text-bad">denied</span>';
  if (key === 'ok' && typeof v === 'boolean') return v ? '<span class="text-ok">ok</span>' : '<span class="font-medium text-bad">failed</span>';
  if (key === 'value' && typeof v === 'boolean') return `<span class="font-mono ${v ? 'text-ok' : 'text-muted'}">${v}</span>`;
  if (key === 'pool' && typeof v === 'number') return `<span class="font-mono">#${v}</span>`;
  if (isThrowable(v)) return exceptionHtml(v, { limit: 140 });
  if (key === 'previous' && Array.isArray(v) && v.length && v.every(isThrowable)) return previousHtml(v, 140);
  if (key === 'type' && (v === 'miss' || v === 'failed')) return `<span class="font-medium text-warn">${esc(v)}</span>`;
  if ((key === 'repeat_of' || key === 'duplicate_of') && v) return `<span class="rounded bg-warn-soft px-1.5 font-mono text-[10.5px] text-warn">${key === 'repeat_of' ? 'repeat' : 'duplicate'} of #${esc(v)}</span>`;
  if (Array.isArray(v) && v.length && v.every((c) => c && typeof c === 'object' && 'field' in c && 'from' in c && 'to' in c)) return changesTable(v);
  if (Array.isArray(v) && v.length && v.every((c) => c && typeof c === 'object' && 'field' in c && 'rule' in c)) {
    return v.map((c) => `<div><span class="font-mono">${esc(c.field)}</span> <span class="text-warn">${esc(c.rule)}${c.parameters?.length ? esc('(' + c.parameters.join(', ') + ')') : ''}</span>${c.message ? ` <span class="text-muted">${esc(c.message)}</span>` : ''}</div>`).join('');
  }
  if (key === 'action' && row.rolled_back) return `${esc(v)} <span class="ml-1 whitespace-nowrap rounded bg-warn-soft px-1.5 font-mono text-[10.5px] text-warn">rolled back</span>`;
  if (key === 'passed' && typeof v === 'boolean') return v ? '<span class="text-ok">passed</span>' : '<span class="font-medium text-warn">failed</span>';
  const text = fmtValue(v);
  const shown = text.length > 240 ? text.slice(0, 240) + '...' : text;
  const html = row._links?.[key] ? maybeLink(shown, row._links[key]) : classes(shown);
  return COPY_COLUMNS.includes(key) && text !== '' ? `<span class="group/copy">${html} ${copyButton(text, humanize(key).toLowerCase(), { hover: true })}</span>` : html;
}
