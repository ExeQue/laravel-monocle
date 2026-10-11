import { esc, ms, fmtValue, maybeLink, copyButton } from '../format.js';
import { rowBody } from '../popover.js';

/**
 * The details of an expanded row, for sections whose lens has no details view: every field.
 * (Lenses with a view, like http, validation and exceptions, are rendered on the server.)
 */
export function details(row) {
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



