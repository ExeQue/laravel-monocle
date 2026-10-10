import '../css/dashboard.css';
import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import { esc, ms, ago, humanize, fmtValue, maybeLink } from './format.js';
import { cls, statusClass } from './classes.js';
import { indexTree, renderTimeline, phasesOf, LENS_COLOR } from './timeline.js';
import { setupPopover, rowDetails } from './popover.js';

const PHASE_COLORS = {
  bootstrap: 'var(--c-phase)', 'middleware (request)': 'var(--c-mw)', routing: 'var(--c-phase)', controller: 'var(--c-ctrl)',
  'middleware (response)': 'color-mix(in srgb, var(--c-mw) 75%, var(--panel))', response: 'var(--c-phase)', terminate: 'var(--c-phase)', other: 'var(--line)',
};
const CATEGORY_COLORS = { db: 'var(--c-db)', cache: 'var(--c-cache)', redis: 'var(--c-io)', views: 'var(--c-view)', http: 'var(--c-io)', fs: 'var(--c-io)', ai: 'var(--c-ctrl)', jobs_sync: 'var(--c-mw)' };

/** Totals grouped by subject: [heading, which keys belong]. Keys no group claims land under "Other". */
const TOTAL_GROUPS = [
  ['Overall', (k) => ['duration_ms', 'memory_peak_mb', 'response_bytes'].includes(k) || k.startsWith('middleware_')],
  ['Database', (k) => k === 'queries' || k.startsWith('query') || k.startsWith('queries_') || ['slow_queries', 'duplicate_queries', 'transactions'].includes(k)],
  ['Cache', (k) => k.startsWith('cache_') || k === 'response_cache'],
  ['Redis', (k) => k.startsWith('redis_')],
  ['Views', (k) => k.startsWith('views_')],
  ['Models', (k) => k.startsWith('models_')],
  ['Livewire', (k) => k.startsWith('livewire_')],
  ['Jobs', (k) => k.startsWith('jobs_')],
  ['AI', (k) => k.startsWith('ai_') || k.startsWith('tokens')],
  ['HTTP', (k) => k.startsWith('http_')],
  ['Files', (k) => k.startsWith('fs_') || k.startsWith('media_')],
  ['Mail', (k) => k.startsWith('mail') || k.startsWith('notification')],
  ['Auth', (k) => k.startsWith('auth_')],
  ['Features', (k) => k.startsWith('features_')],
  ['Authorization', (k) => k.startsWith('authorization_')],
];
const SKIP_SECTIONS = ['summary', 'timeline', 'request', 'session', 'meta', 'queries'];
/** Section tabs, most useful first; sections not listed follow in their own order. */
const TAB_ORDER = ['exceptions', 'response_cache', 'auth', 'features', 'cache', 'views', 'http', 'ai', 'fs', 'media', 'jobs', 'livewire', 'models', 'model_changes', 'mail', 'log', 'dumps', 'redis', 'events', 'authorization', 'middleware', 'mcp', 'scheduled_tasks'];
const rank = (key) => { const i = TAB_ORDER.indexOf(key); return i === -1 ? TAB_ORDER.length : i; };

const theme = {
  get() { try { return localStorage.getItem('monocle.theme') ?? 'system'; } catch { return 'system'; } },
  set(value) {
    try { localStorage.setItem('monocle.theme', value); } catch { /* storage blocked */ }
    value === 'system' ? document.documentElement.removeAttribute('data-theme') : document.documentElement.setAttribute('data-theme', value);
  },
};
theme.set(theme.get());

/** Capture names the viewer chose to hide; kept in this browser only. */
const hiddenNames = {
  get() {
    try { const stored = JSON.parse(localStorage.getItem('monocle.hidden') ?? 'null'); return Array.isArray(stored) ? stored : []; } catch { return []; }
  },
  set(names) { try { localStorage.setItem('monocle.hidden', JSON.stringify(names)); } catch { /* storage blocked */ } },
};

/** A {field, from, to} list as a small table. */
const changesTable = (changes) => `<table class="w-full table-fixed border-collapse text-xs"><colgroup><col class="w-[28%]"><col class="w-[36%]"><col class="w-[36%]"></colgroup><thead><tr>${['Field', 'From', 'To'].map((h) => `<th class="px-1.5 py-0.5 text-left font-medium text-muted">${h}</th>`).join('')}</tr></thead><tbody>${changes.map((c) => `<tr class="border-t border-line"><td class="px-1.5 py-0.5 align-top font-mono [overflow-wrap:anywhere]">${esc(c.field)}</td><td class="px-1.5 py-0.5 align-top font-mono text-bad [overflow-wrap:anywhere]">${c.from === null ? '<span class="text-muted">null</span>' : esc(fmtValue(c.from))}</td><td class="px-1.5 py-0.5 align-top font-mono text-ok [overflow-wrap:anywhere]">${c.to === null ? '<span class="text-muted">null</span>' : esc(fmtValue(c.to))}</td></tr>`).join('')}</tbody></table>`;

Alpine.data('monocle', (config) => ({
  api: config.api,
  captures: [],
  total: 0,
  filters: { type: '', q: '', errors: false },
  hidden: hiddenNames.get(),
  live: true,
  selectedId: null,
  capture: null,
  model: null,
  tab: 'overview',
  tabData: null,
  tabLoading: false,
  groupBy: 'order',
  timelineState: { open: new Set(), shut: new Set(), kinds: new Set() },
  timelineHtml: '',
  foldable: [],
  theme: theme.get(),
  error: null,
  confirmClear: false,
  listOpen: false,
  copied: null,
  responseView: 'pretty',
  wide: true,

  init() {
    const wide = matchMedia('(min-width: 861px)');
    this.wide = wide.matches;
    wide.addEventListener('change', (e) => { this.wide = e.matches; });
    this.popover = setupPopover(this.$refs.detail, () => this.model);
    const fromHash = location.hash.replace(/^#/, '').split('/');
    if (fromHash[0]) { this.selectedId = fromHash[0]; this.tab = fromHash[1] || 'overview'; }
    this.load();
    setInterval(() => this.live && !document.hidden && this.load(true), 2500);
    this.$watch('filters', () => this.load(), { deep: true });
    addEventListener('keydown', (e) => {
      if (e.metaKey || e.ctrlKey || e.altKey || e.target.closest?.('input, textarea, select, [contenteditable]')) return;
      const step = { j: 1, ArrowDown: 1, k: -1, ArrowUp: -1 }[e.key];
      if (!step || !this.captures.length) return;
      e.preventDefault();
      const index = this.captures.findIndex((c) => c.id === this.selectedId);
      const next = this.captures[Math.max(0, Math.min(this.captures.length - 1, index + step))];
      if (next && next.id !== this.selectedId) {
        this.select(next.id, ['overview', 'timeline', 'queries'].includes(this.tab) ? this.tab : 'overview');
        this.$nextTick(() => document.querySelector(`[aria-current="true"]`)?.scrollIntoView({ block: 'nearest' }));
      }
    });
  },

  async fetchJson(url, options = {}) {
    const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': config.csrf, ...(options.headers ?? {}) }, ...options });
    if (!response.ok) throw new Error(response.status === 403 ? 'You are not allowed to view Monocle here.' : `The request failed (${response.status}).`);
    return response.json();
  },

  async load(quiet = false) {
    const params = new URLSearchParams({ limit: 200 });
    if (this.filters.type) params.set('type', this.filters.type);
    if (this.filters.q) params.set('uri', this.filters.q);
    if (this.filters.errors) params.set('has_errors', '1');
    this.hidden.forEach((name) => params.append('exclude[]', name));
    try {
      const result = await this.fetchJson(`${this.api}?${params}`);
      this.captures = result.rows;
      this.total = result.total;
      this.error = null;
      if (!this.selectedId && this.captures[0]) this.select(this.captures[0].id);
      else if (!quiet && this.selectedId && !this.capture) this.select(this.selectedId, this.tab);
    } catch (e) {
      this.error = e.message;
    }
  },

  async select(id, tab = 'overview') {
    this.popover.close();
    this.listOpen = false;
    this.selectedId = id;
    this.tab = tab;
    this.timelineState = { open: new Set(), shut: new Set(), kinds: new Set() };
    this.tabData = null;
    try {
      const capture = await this.fetchJson(`${this.api}/${encodeURIComponent(id)}`);
      this.capture = capture;
      this.model = { tree: indexTree(capture.timeline), duration: capture.summary?.totals?.duration_ms ?? this.duration(capture) };
      history.replaceState(null, '', `#${id}/${this.tab}`);
      this.openTab(this.tab);
    } catch (e) {
      this.capture = null;
      this.error = e.message;
    }
  },

  duration(capture) {
    const row = this.captures.find((c) => c.id === capture.id);
    return row?.duration ?? 0;
  },

  async openTab(tab) {
    this.popover.close();
    this.tab = tab;
    this.tabData = null;
    history.replaceState(null, '', `#${this.selectedId}/${tab}`);
    this.$nextTick(() => this.$refs.tabs?.querySelector('[aria-selected="true"]')?.scrollIntoView({ inline: 'nearest', block: 'nearest', behavior: 'smooth' }));
    if (tab === 'timeline') return this.renderTimeline();
    if (tab === 'overview') return;
    this.tabLoading = true;
    try {
      const base = `${this.api}/${encodeURIComponent(this.selectedId)}`;
      this.tabData = tab === 'queries' && this.groupBy === 'order'
        ? await this.fetchJson(`${base}/rows/queries?limit=500&verbose=1`)
        : tab === 'queries'
        ? await this.fetchJson(`${base}/queries?group_by=${this.groupBy}&limit=200`)
        : tab === 'trace'
          ? await this.fetchJson(`${base}/trace`)
          : await this.fetchJson(`${base}/rows/${encodeURIComponent(tab)}?limit=100&verbose=1`);
    } catch (e) {
      this.error = e.message;
    } finally {
      this.tabLoading = false;
    }
  },

  async loadMore() {
    if (this.tabData?.next_offset == null) return;
    const limit = this.tab === 'queries' ? 500 : 100;
    const more = await this.fetchJson(`${this.api}/${encodeURIComponent(this.selectedId)}/rows/${encodeURIComponent(this.tab)}?limit=${limit}&offset=${this.tabData.next_offset}&verbose=1`);
    this.tabData = { ...more, rows: [...this.tabData.rows, ...more.rows] };
  },

  renderTimeline() {
    const rendered = this.model ? renderTimeline(this.model, this.timelineState.kinds) : { html: '', foldable: [] };
    this.timelineHtml = rendered.html;
    this.foldable = rendered.foldable;
  },

  timelineClick(e) {
    const t = e.target.closest('button[data-kind]');
    if (!t) return;
    const kinds = this.timelineState.kinds;
    kinds.has(t.dataset.kind) ? kinds.delete(t.dataset.kind) : kinds.add(t.dataset.kind);
    this.popover.close();
    this.renderTimeline();
  },

  tlOpen(id, fallback) {
    const s = this.timelineState;
    return s.open.has(id) ? true : s.shut.has(id) ? false : fallback;
  },

  tlToggle(id, fallback) {
    const s = this.timelineState;
    this.popover.close();
    if (this.tlOpen(id, fallback)) { s.open.delete(id); s.shut.add(id); } else { s.shut.delete(id); s.open.add(id); }
  },

  tlAll(expand) {
    const s = this.timelineState;
    this.popover.close();
    for (const { id } of this.foldable) {
      if (expand) { s.shut.delete(id); s.open.add(id); } else { s.open.delete(id); s.shut.add(id); }
    }
  },

  async clearAll() {
    await this.fetchJson(this.api, { method: 'DELETE' });
    this.confirmClear = false;
    this.capture = null;
    this.model = null;
    this.selectedId = null;
    history.replaceState(null, '', '#');
    this.load();
  },

  setTheme(value) { this.theme = value; theme.set(value); },

  hide(name) {
    if (!this.hidden.includes(name)) this.hidden = [...this.hidden, name];
    hiddenNames.set(this.hidden);
    this.load();
  },

  unhide(name) {
    this.hidden = this.hidden.filter((n) => n !== name);
    hiddenNames.set(this.hidden);
    this.load();
  },

  get tabs() {
    if (!this.capture) return [];
    const counts = this.capture.summary?.counts ?? {};
    const tabs = [{ key: 'overview', label: 'Overview' }, { key: 'timeline', label: 'Timeline' }];
    const has = (key) => (this.capture.available_sections ?? []).some((s) => s.key === key && s.rows !== 0);
    const isRequest = this.summary.type === 'request';
    if (isRequest && has('request')) tabs.push({ key: 'request', label: 'Request' });
    if (isRequest && has('session')) tabs.push({ key: 'session', label: 'Session' });
    if (counts.queries) tabs.push({ key: 'queries', label: 'Queries', count: counts.queries });
    const sections = (this.capture.available_sections ?? []).filter((s) => !SKIP_SECTIONS.includes(s.key) && s.rows);
    for (const s of sections.sort((a, b) => rank(a.key) - rank(b.key))) {
      tabs.push({ key: s.key, label: humanize(s.key), count: s.rows, description: s.description });
    }
    tabs.push({ key: 'trace', label: 'Trace' });
    return tabs;
  },

  get summary() { return this.capture?.summary ?? {}; },
  get phases() { return this.summary.breakdown?.phases ?? []; },
  get categories() { return this.summary.breakdown?.categories ?? []; },
  get maxCategory() { return Math.max(1, ...this.categories.map((c) => c.ms)); },

  /** Totals grouped by what they are about, each group in a fixed order. */
  get totalGroups() {
    const totals = this.summary.totals ?? {};
    const shown = (k, v) => v !== null && v !== undefined && typeof v !== 'object' && (v !== 0 || k === 'queries');
    const taken = new Set();
    const groups = TOTAL_GROUPS.map(([label, test]) => {
      const items = Object.entries(totals).filter(([k, v]) => !taken.has(k) && test(k) && shown(k, v));
      items.forEach(([k]) => taken.add(k));
      return { label, items };
    });
    groups.push({ label: 'Other', items: Object.entries(totals).filter(([k, v]) => !taken.has(k) && shown(k, v)) });
    return groups.filter((g) => g.items.length);
  },


  /** Original query row number => how many later queries repeat it. */
  get duplicates() {
    const counts = {};
    for (const q of this.tabData?.rows ?? []) if (q.duplicate_of) counts[q.duplicate_of] = (counts[q.duplicate_of] ?? 0) + 1;
    return counts;
  },

  /** Nested input, cookies and session data as dot.notated key/value pairs. */
  dot(value, prefix = '') {
    if (value === null || typeof value !== 'object') return prefix ? [[prefix, value]] : [];
    const entries = Array.isArray(value) ? value.map((v, i) => [String(i), v]) : Object.entries(value);
    if (!entries.length) return prefix ? [[prefix, Array.isArray(value) ? '[]' : '{}']] : [];
    return entries.flatMap(([k, v]) => this.dot(v, prefix ? `${prefix}.${k}` : k));
  },

  /** A payload value; JSON strings (Livewire snapshots, API bodies) are shown formatted. */
  payloadValue(v) {
    if (typeof v === 'string' && /^\s*[[{]/.test(v)) {
      try { return `<pre class="m-0 max-h-64 overflow-auto whitespace-pre-wrap break-all">${esc(JSON.stringify(JSON.parse(v), null, 2))}</pre>`; } catch { /* not JSON */ }
    }
    return esc(fmtValue(v));
  },

  headers(headers) {
    return Object.entries(headers ?? {}).map(([name, values]) => [name, Array.isArray(values) ? values.join(', ') : fmtValue(values)]);
  },

  body(text, type) {
    if (typeof text !== 'string') return '';
    if (/json/i.test(type ?? '')) { try { return JSON.stringify(JSON.parse(text), null, 2); } catch { /* not valid JSON */ } }
    return text;
  },

  /** A value from the open assoc section, linked when the server found an editor link for its path. */
  linked(value, path) {
    const link = this.tabData?.links?.[path];
    return link ? maybeLink(fmtValue(value), link) : esc(fmtValue(value));
  },

  changesTable(changes) { return changesTable(changes); },

  async copy(text, what = 'id') {
    try {
      await navigator.clipboard.writeText(text);
    } catch {
      const range = document.createRange();
      const node = document.createElement('textarea');
      node.value = text;
      document.body.append(node);
      range.selectNodeContents(node);
      node.select();
      getSelection().removeAllRanges();
      getSelection().addRange(range);
      document.execCommand('copy');
      node.remove();
    }
    this.copied = what;
    setTimeout(() => { if (this.copied === what) this.copied = null; }, 1500);
  },

  /** A prompt for the coding agent to look into this capture through the Monocle MCP server. */
  get agentPrompt() {
    const s = this.summary;
    const lines = [
      `Use the Monocle MCP server to look into capture ${this.capture.id}: ${s.name} (${s.type}${s.status != null ? `, status ${s.status}` : ''}, ${ms(s.totals?.duration_ms)}).`,
      `Start with monocle-show id=${this.capture.id}, then dig into the sections that matter with monocle-rows and monocle-queries.`,
    ];
    const totals = this.totalGroups.map((g) => `- ${g.label}: ${g.items.map((t) => `${this.totalLabel(t[0], g.label).toLowerCase()} ${this.totalValue(t)}`).join(', ')}`);
    if (totals.length) lines.push('', 'Totals:', ...totals);
    if (s.warnings?.length) lines.push('', 'Monocle flagged:', ...s.warnings.map((w) => `- ${w}`));
    lines.push('', 'Explain what is slow or wrong, point at the file:line to change (every row has an origin), make the change, reproduce the request and compare the new capture with monocle-compare.');
    return lines.join('\n');
  },

  /** Open the details of a table row next to the click. */
  rowPopover(event, row, section) {
    if (event.target.closest('a, button')) return;
    event.stopPropagation();
    this.popover.openDetails(event.currentTarget, rowDetails(row, section), event.clientX);
  },

  linkHtml(text, link) { return text ? maybeLink(text, link) : ''; },

  /** One item of a response capture card. */
  cardValue(value, key) {
    if (Array.isArray(value) && value.length && value.every((c) => c && typeof c === 'object' && 'field' in c && 'from' in c && 'to' in c)) return changesTable(value);
    if (key.endsWith('_bytes') && typeof value === 'number') return esc(this.totalValue([key, value]));
    if (Array.isArray(value) && value.length && value.every((v) => v && typeof v === 'object' && !Array.isArray(v))) {
      const cols = [...new Set(value.flatMap((v) => Object.keys(v)))];
      const cell = (v) => (v === null || v === undefined ? '<span class="text-muted">-</span>' : esc(typeof v === 'object' ? JSON.stringify(v) : String(v)));
      return `<table class="w-full border-collapse text-xs"><thead><tr>${cols.map((c) => `<th class="px-1.5 py-0.5 text-left font-medium text-muted">${esc(humanize(c))}</th>`).join('')}</tr></thead><tbody>${value.map((row) => `<tr class="border-t border-line">${cols.map((c) => `<td class="px-1.5 py-0.5 align-top [overflow-wrap:anywhere]">${cell(row[c])}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
    }
    if (key === 'body' && typeof value === 'string') return `<pre class="m-0 max-h-96 overflow-auto whitespace-pre-wrap">${esc(value)}</pre>`;
    if (Array.isArray(value) && value.every((v) => v === null || typeof v !== 'object')) return value.map((v) => `<div>${esc(fmtValue(v))}</div>`).join('');
    if (value && typeof value === 'object') return this.dot(value).map(([k, v]) => `<div><span class="text-muted">${esc(k)}</span> ${esc(fmtValue(v))}</div>`).join('');
    return esc(fmtValue(value));
  },

  get request() { return this.tab === 'request' ? (this.tabData?.data ?? null) : null; },
  get session() { return this.tab === 'session' ? (this.tabData?.data ?? null) : null; },

  get tableColumns() {
    const rows = this.tabData?.rows ?? [];
    const keys = [];
    for (const row of rows.slice(0, 20)) for (const k of Object.keys(row)) if (!keys.includes(k) && k !== 'n' && k !== '_links') keys.push(k);
    const blank = (v) => v === null || v === undefined || v === '' || (Array.isArray(v) && v.length === 0);
    const used = keys.filter((k) => rows.some((row) => !blank(row[k])));
    return used.filter((k) => k !== 'origin').slice(0, 8).concat(used.includes('origin') ? ['origin'] : []);
  },

  cell(row, key) {
    const v = row[key];
    if (key === 'origin') return v?.file ? maybeLink(v.file + (v.line ? ':' + v.line : ''), v.link, cls.origin) + (v.via ? `<span class="block font-mono text-[11px] text-muted">via ${maybeLink(v.via.call ?? v.via.file, v.via.call_link ?? v.via.link)}</span>` : '') : '';
    if ((key === 'duration' || key === 'time' || key.endsWith('_ms')) && typeof v === 'number') return `<span class="whitespace-nowrap">${esc(ms(v))}</span>`;
    if ((key === 'bytes' || key.endsWith('_bytes') || key === 'size') && typeof v === 'number') return '<span class="whitespace-nowrap">' + esc(v >= 1048576 ? (v / 1048576).toFixed(1) + ' MB' : v >= 1024 ? (v / 1024).toFixed(1) + ' KB' : v + ' B') + '</span>';
    if (key === 'type' && (v === 'miss' || v === 'failed')) return `<span class="font-medium text-warn">${esc(v)}</span>`;
    if ((key === 'repeat_of' || key === 'duplicate_of') && v) return `<span class="rounded bg-warn-soft px-1.5 font-mono text-[10.5px] text-warn">${key === 'repeat_of' ? 'repeat' : 'duplicate'} of #${esc(v)}</span>`;
    if (Array.isArray(v) && v.length && v.every((c) => c && typeof c === 'object' && 'field' in c && 'from' in c && 'to' in c)) return changesTable(v);
    const text = fmtValue(v);
    const shown = text.length > 240 ? text.slice(0, 240) + '...' : text;
    return row._links?.[key] ? maybeLink(shown, row._links[key]) : esc(shown);
  },

  // Template helpers
  cls, ms, ago, statusClass, humanize, esc,
  phaseColor(name) { return PHASE_COLORS[name] ?? 'var(--c-phase)'; },
  categoryColor(name) { return CATEGORY_COLORS[name] ?? LENS_COLOR[name] ?? 'var(--c-phase)'; },
  totalValue([k, v]) {
    if (k.endsWith('_ms')) return ms(v);
    if (k.endsWith('_rate')) return v + ' %';
    if (k.endsWith('_bytes')) return v >= 1048576 ? (v / 1048576).toFixed(1) + ' MB' : Math.round(v / 1024) + ' KB';
    if (k.endsWith('_mb')) return v + ' MB';
    return String(v);
  },
  totalLabel(k, group = null) {
    const labels = { duration_ms: 'Duration', query_ms: 'Query time', cache_ms: 'Cache time', redis_ms: 'Redis time', views_ms: 'View time', middleware_self_ms: 'Middleware time', memory_peak_mb: 'Peak memory', response_bytes: 'Response size', cache_hit_rate: 'Cache hit rate' };
    const label = labels[k] ?? humanize(k.replace(/_(ms|mb|bytes)$/, ''));
    const lower = label.toLowerCase();
    const prefix = group ? [`${group} `, `${group.replace(/s$/, '')} `].map((p) => p.toLowerCase()).find((p) => lower.startsWith(p) && label.length > p.length) : null;
    return prefix ? humanize(label.slice(prefix.length)) : label;
  },
  originHtml(origin) { return origin?.file ? maybeLink(origin.file + (origin.line ? ':' + origin.line : ''), origin.link, cls.origin) : ''; },
}));

Alpine.plugin(collapse);
window.Alpine = Alpine;
Alpine.start();
