import { esc, ms, humanize, fmtValue, maybeLink, copyButton, exceptionHtml } from '../format.js';
import { httpStatusClass } from '../classes.js';
import { rowBody, originHtml as originBlock } from '../popover.js';
import { body, cardValue, dot, headers, payloadValue } from './values.js';

/** The details of an expanded row: HTTP calls get their own layout, other rows list every field. */
export function details(row, section) {
  if (section === 'http') return httpDetails(row);
  if (section === 'validation') return validationDetails(row);
  return rowBody(row);
}

/** Everything recorded about one query: its bindings, where it ran and how it was called. */
export function queryDetails(q) {
  const label = 'text-[11px] uppercase tracking-wider text-muted';
  const facts = [
    ['Connection', q.connection],
    ['Driver', q.driver],
    ['Type', q.type],
    ['Tables', (q.tables ?? []).join(', ')],
    ['Transaction', q.transaction_id],
    ['Started at', q.time != null ? ms(q.time) : null],
    ['Duration', q.duration != null ? ms(q.duration) : null],
  ].filter(([, v]) => v !== null && v !== undefined && v !== '');
  const bindings = q.bindings ?? [];
  const frames = q.origin?.frames ?? [];

  const heading = (text, copy, what) => `<div class="flex items-center gap-1.5"><div class="${label}">${text}</div>${copy ? copyButton(copy, what) : ''}</div>`;

  return `<div class="grid gap-3 text-xs">
    ${q.sql_with_bindings ? `<div class="grid gap-1">${heading('Query', q.sql_with_bindings, 'query with bindings')}<code class="block font-mono whitespace-pre-wrap [overflow-wrap:anywhere]">${esc(q.sql_with_bindings)}</code></div>` : ''}
    <div class="grid gap-1">${heading('Query with placeholders', q.sql ?? '', 'query with placeholders')}<code class="block font-mono whitespace-pre-wrap [overflow-wrap:anywhere]">${esc(q.sql ?? '')}</code></div>
    <div class="grid gap-1">${heading('Bindings', bindings.length ? JSON.stringify(bindings) : '', 'bindings as JSON')}${bindings.length
      ? `<table class="w-fit border-collapse"><tbody>${bindings.map((b, i) => `<tr class="group/copy"><td class="pr-3 font-mono text-muted tabular-nums">?${i + 1}</td><td class="font-mono [overflow-wrap:anywhere]">${b === null ? '<span class="text-muted">null</span>' : esc(fmtValue(b))}</td><td class="pl-2">${b === null ? '' : copyButton(fmtValue(b), `binding ${i + 1}`, { hover: true })}</td></tr>`).join('')}</tbody></table>`
      : '<span class="text-muted">none</span>'}</div>
    <dl class="m-0 grid grid-cols-[max-content_minmax(0,1fr)] gap-x-4 gap-y-1">${facts.map(([k, v]) => `<dt class="text-muted">${k}</dt><dd class="m-0 font-mono [overflow-wrap:anywhere]">${esc(String(v))}</dd>`).join('')}</dl>
    ${frames.length ? `<div class="grid gap-1"><div class="${label}">Call chain</div><ol class="m-0 grid list-decimal gap-0.5 pl-5">${frames.map((f) => `<li class="font-mono">${maybeLink((f.file ?? '') + (f.line ? ':' + f.line : ''), f.link)} ${maybeLink(f.call ?? '', f.call_link)}</li>`).join('')}</ol></div>` : ''}
  </div>`;
}

/** An outgoing HTTP call: facts, both header sets, the payload sent and the response received. */
export function httpDetails(row) {
  const label = 'text-[11px] uppercase tracking-wider text-muted';
  const td = 'border-t border-line px-2 py-1 align-top font-mono text-xs [overflow-wrap:anywhere]';
  const kvTable = (entries, what) => entries.length
    ? `<table class="w-full border-collapse">${entries.map(([k, v]) => `<tr class="group/copy"><td class="${td} w-0 whitespace-nowrap text-muted">${esc(k)}</td><td class="${td}">${payloadValue(v)}</td><td class="${td} w-0">${v === null ? '' : copyButton(typeof v === 'object' ? JSON.stringify(v) : String(v), `${what} ${k}`, { hover: true })}</td></tr>`).join('')}</table>`
    : '<span class="text-xs text-muted">none</span>';
  const block = (title, html, copy = null) => `<div class="grid min-w-0 content-start gap-1.5"><div class="flex items-center gap-1.5"><div class="${label}">${title}</div>${copy ? copyButton(copy, title.toLowerCase()) : ''}</div>${html}</div>`;
  const size = (v) => (typeof v !== 'number' ? null : v >= 1048576 ? (v / 1048576).toFixed(1) + ' MB' : v >= 1024 ? (v / 1024).toFixed(1) + ' KB' : v + ' B');
  const facts = [
    ['Status', row.status ?? 'no response', httpStatusClass(row.status)],
    ['Duration', row.duration != null ? ms(row.duration) : null],
    ['Started at', row.time != null ? ms(row.time) : null],
    ['Sent', size(row.request_bytes)],
    ['Received', size(row.response_bytes)],
    ['Pool', row.pool == null ? null : `#${row.pool}`],
  ].filter(([, v]) => v !== null && v !== undefined && v !== '');
  const capture = row.response_capture;
  const cards = (capture?.cards ?? []).map((card) => `<div class="grid gap-1 rounded-md border border-line bg-panel p-2.5"><div><b class="text-[13px]">${esc(card.title)}</b>${card.subtitle ? ` <span class="text-xs text-muted">${esc(card.subtitle)}</span>` : ''}</div><dl class="m-0 grid grid-cols-[max-content_minmax(0,1fr)] gap-x-3 gap-y-1 text-xs">${Object.entries(card.items ?? {}).map(([k, v]) => `<dt class="text-muted">${esc(humanize(k))}</dt><dd class="m-0 font-mono [overflow-wrap:anywhere]">${cardValue(v, k)}</dd>`).join('')}</dl></div>`).join('');
  const raw = capture?.raw ?? row.response_body;
  const type = headers(row.response_headers).find(([k]) => k.toLowerCase() === 'content-type')?.[1];
  const pre = (text) => `<pre class="scroll-quiet m-0 max-h-80 overflow-auto rounded-md border border-line bg-panel p-2.5 font-mono text-xs whitespace-pre-wrap [overflow-wrap:anywhere]">${esc(body(text, type))}</pre>`;
  const response = cards
    ? `${cards}${raw ? `<details><summary class="cursor-pointer text-xs text-muted">Raw body</summary><div class="mt-1.5">${pre(raw)}</div></details>` : ''}`
    : raw ? pre(raw) : '<span class="text-xs text-muted">not recorded</span>';

  return `<div class="grid gap-4">
    <div class="flex flex-wrap items-start gap-1.5 font-mono text-xs"><b>${esc(row.method ?? '')}</b><span class="[overflow-wrap:anywhere]">${esc(row.url ?? '')}</span>${copyButton(row.url ?? '', 'url')}</div>
    <dl class="m-0 grid grid-cols-[repeat(auto-fill,minmax(110px,1fr))] gap-3">${facts.map(([k, v, tone]) => `<div class="grid gap-0.5"><dt class="${label}">${k}</dt><dd class="m-0 text-[13px] tabular-nums [overflow-wrap:anywhere] ${tone ?? ''}">${esc(String(v))}</dd></div>`).join('')}</dl>
    ${row.timing ? block('Timing', httpTiming(row.timing)) : ''}
    ${row.exception ? block('Exception', `<div class="rounded-md border border-line bg-panel p-2.5 text-xs">${exceptionHtml(row.exception, { full: true })}</div>`, [row.exception, ...(row.exception.previous ?? [])].map((x) => `${x.class}: ${x.message}`).join('\n')) : ''}
    <div class="grid gap-4 lg:grid-cols-2">
      ${block('Request headers', kvTable(headers(row.request_headers), 'request header'))}
      ${block('Response headers', kvTable(headers(row.response_headers), 'response header'))}
    </div>
    <div class="grid gap-4 lg:grid-cols-2">
      ${block('Payload', row.request_payload ? kvTable(dot(row.request_payload), 'payload') : row.request_body ? pre(row.request_body) : '<span class="text-xs text-muted">none</span>', row.request_payload ? JSON.stringify(row.request_payload) : row.request_body)}
      ${block('Response', response, raw)}
    </div>
    ${originBlock(row.origin)}
  </div>`;
}

/** One validation: a row per field with its rules, the input and what failed, then the rest. */
export function validationDetails(row) {
  const td = 'border-t border-line px-2 py-1.5 align-top';
  const failed = {};
  (row.failed ?? []).forEach((f) => { (failed[f.field] ??= []).push(f); });
  const fields = [...new Set([...Object.keys(row.rules ?? {}), ...Object.keys(failed)])];
  const size = (v) => (v >= 1048576 ? (v / 1048576).toFixed(1) + ' MB' : v >= 1024 ? (v / 1024).toFixed(1) + ' KB' : v + ' B');
  const input = (field) => {
    const v = dot(row.input ?? {}).find(([k]) => k === field)?.[1] ?? (row.input ?? {})[field.split('.')[0]];
    if (v === undefined) return '<span class="text-muted">-</span>';
    if (v === null) return '<span class="text-muted">null</span>';
    if (typeof v === 'object' && 'file' in v && 'size' in v) return `<span class="font-mono">${esc(v.file)}</span> <span class="text-muted">${size(v.size)}${v.mime ? ', ' + esc(v.mime) : ''}</span>`;
    const text = typeof v === 'object' ? JSON.stringify(v) : String(v);
    return `<span class="group/copy"><span class="font-mono" ${text.length > 120 ? `data-tip="${esc(text.slice(0, 1000))}"` : ''}>${esc(text.length > 120 ? text.slice(0, 120) + '…' : text)}</span> ${copyButton(text, `${field} input`, { hover: true })}</span>`;
  };
  const rules = (r) => (Array.isArray(r) ? r : String(r ?? '').split('|')).filter(Boolean).map((x) => `<span class="mr-1 inline-block rounded border border-line px-1 font-mono text-[11px]">${maybeLink(x, row._links?.rules?.[x])}</span>`).join('');
  const result = (field) => failed[field]
    ? failed[field].map((f) => `<div class="text-bad"><span class="font-mono text-[11px]">${esc(f.rule)}${f.parameters?.length ? esc('(' + f.parameters.join(', ') + ')') : ''}</span> ${esc(f.message ?? '')}</div>`).join('')
    : '<span class="text-ok">passed</span>';
  const rest = Object.fromEntries(Object.entries(row).filter(([k]) => !['rules', 'input', 'failed', 'fields', 'passed'].includes(k)));

  return `<div class="grid gap-3">
    <table class="w-full border-collapse text-xs">
      <thead><tr class="text-left text-[11px] uppercase tracking-wider text-muted"><th class="px-2 py-1 font-medium">Field</th><th class="px-2 py-1 font-medium">Rules</th><th class="px-2 py-1 font-medium">Input</th><th class="px-2 py-1 font-medium">Result</th></tr></thead>
      <tbody>${fields.map((field) => `<tr><td class="${td} w-0 whitespace-nowrap font-mono">${esc(field)}</td><td class="${td}">${rules(row.rules?.[field])}</td><td class="${td} [overflow-wrap:anywhere]">${input(field)}</td><td class="${td}">${result(field)}</td></tr>`).join('')}</tbody>
    </table>
    <div class="grid gap-2.5">${rowBody(rest)}</div>
  </div>`;
}

/** Where the time of an HTTP call went: one bar split into its phases, then the connection facts. */
export function httpTiming(timing) {
  const phases = [['dns', 'DNS', 'var(--c-cache)'], ['connect', 'Connect', 'var(--c-db)'], ['tls', 'TLS', 'var(--c-view)'], ['wait', 'Waiting', 'var(--c-http)'], ['download', 'Download', 'var(--c-ctrl)'], ['redirect', 'Redirects', 'var(--c-phase)']]
    .filter(([k]) => typeof timing[k] === 'number');
  const total = phases.reduce((sum, [k]) => sum + timing[k], 0) || 1;
  const facts = [['Remote', timing.remote], ['Protocol', timing.protocol], ['Redirects followed', timing.redirects]].filter(([, v]) => v);
  return `<div class="grid gap-2 text-xs">
    <div class="flex h-2.5 overflow-hidden rounded-sm bg-sunk" role="img" aria-label="${esc(phases.map(([k, l]) => `${l} ${ms(timing[k])}`).join(', '))}">${phases.map(([k, , c]) => `<span style="width:${(timing[k] / total) * 100}%;background:${c}"></span>`).join('')}</div>
    <div class="flex flex-wrap gap-x-4 gap-y-1">${phases.map(([k, l, c]) => `<span class="inline-flex items-center gap-1.5"><i class="size-2 rounded-sm" style="background:${c}"></i><span class="text-muted">${l}</span><span class="tabular-nums">${ms(timing[k])}</span></span>`).join('')}</div>
    ${facts.length ? `<div class="flex flex-wrap gap-x-4 gap-y-1">${facts.map(([l, v]) => `<span><span class="text-muted">${l}</span> <span class="font-mono">${esc(String(v))}</span></span>`).join('')}</div>` : ''}
  </div>`;
}
