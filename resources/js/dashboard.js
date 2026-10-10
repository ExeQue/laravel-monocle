import '../css/dashboard.css';
import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import { esc, ms, ago, humanize, fmtValue, maybeLink } from './format.js';
import { cls, statusClass } from './classes.js';
import { indexTree, renderTimeline, phasesOf, LENS_COLOR } from './timeline.js';
import { setupPopover } from './popover.js';

const PHASE_COLORS = {
  bootstrap: 'var(--c-phase)', 'middleware (request)': 'var(--c-mw)', routing: 'var(--c-phase)', controller: 'var(--c-ctrl)',
  'middleware (response)': 'color-mix(in srgb, var(--c-mw) 55%, var(--panel))', response: 'var(--c-phase)', terminate: 'var(--c-phase)', other: 'var(--line)',
};
const CATEGORY_COLORS = { db: 'var(--c-db)', cache: 'var(--c-cache)', redis: 'var(--c-io)', views: 'var(--c-view)', http: 'var(--c-io)', fs: 'var(--c-io)', ai: 'var(--c-ctrl)', jobs_sync: 'var(--c-mw)' };

/** Totals shown first, in this order; everything else follows. */
const KEY_TOTALS = ['duration_ms', 'queries', 'query_ms', 'cache_hit_rate', 'views_rendered', 'models_retrieved', 'redis_commands', 'memory_peak_mb', 'response_bytes', 'tokens_total', 'jobs_dispatched'];
const SKIP_SECTIONS = ['summary', 'timeline', 'request', 'session', 'meta', 'queries'];
/** Section tabs, most useful first; sections not listed follow in their own order. */
const TAB_ORDER = ['exceptions', 'cache', 'views', 'http', 'ai', 'fs', 'jobs', 'livewire', 'models', 'model_changes', 'mail', 'log', 'dumps', 'redis', 'events', 'authorization', 'middleware', 'mcp', 'scheduled_tasks'];
const rank = (key) => { const i = TAB_ORDER.indexOf(key); return i === -1 ? TAB_ORDER.length : i; };

const theme = {
  get() { try { return localStorage.getItem('monocle.theme') ?? 'system'; } catch { return 'system'; } },
  set(value) {
    try { localStorage.setItem('monocle.theme', value); } catch { /* storage blocked */ }
    value === 'system' ? document.documentElement.removeAttribute('data-theme') : document.documentElement.setAttribute('data-theme', value);
  },
};
theme.set(theme.get());

/** A {field, from, to} list as a small table. */
const changesTable = (changes) => `<table class="w-full border-collapse text-xs"><thead><tr>${['Field', 'From', 'To'].map((h) => `<th class="px-1.5 py-0.5 text-left font-medium text-muted">${h}</th>`).join('')}</tr></thead><tbody>${changes.map((c) => `<tr class="border-t border-line"><td class="px-1.5 py-0.5 font-mono">${esc(c.field)}</td><td class="px-1.5 py-0.5 font-mono text-bad [overflow-wrap:anywhere]">${c.from === null ? '<span class="text-muted">null</span>' : esc(fmtValue(c.from))}</td><td class="px-1.5 py-0.5 font-mono text-ok [overflow-wrap:anywhere]">${c.to === null ? '<span class="text-muted">null</span>' : esc(fmtValue(c.to))}</td></tr>`).join('')}</tbody></table>`;

Alpine.data('monocle', (config) => ({
  api: config.api,
  captures: [],
  total: 0,
  filters: { type: '', q: '', errors: false },
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

  init() {
    this.popover = setupPopover(this.$refs.detail, () => this.model);
    const fromHash = location.hash.replace(/^#/, '').split('/');
    if (fromHash[0]) { this.selectedId = fromHash[0]; this.tab = fromHash[1] || 'overview'; }
    this.load();
    setInterval(() => this.live && !document.hidden && this.load(true), 2500);
    this.$watch('filters', () => this.load(), { deep: true });
  },

  async fetchJson(url, options = {}) {
    const response = await fetch(url, { headers: { Accept: 'application/json', 'X-CSRF-TOKEN': config.csrf, ...(options.headers ?? {}) }, ...options });
    if (!response.ok) throw new Error(response.status === 403 ? 'You are not allowed to view Monocle here.' : `The request failed (${response.status}).`);
    return response.json();
  },

  async load(quiet = false) {
    const params = new URLSearchParams({ limit: 200 });
    if (this.filters.type) params.set('type', this.filters.type);
    if (this.filters.q) params.set('uri', this.filters.q);
    if (this.filters.errors) params.set('has_errors', '1');
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
    if (tab === 'timeline') return this.renderTimeline();
    if (tab === 'overview') return;
    this.tabLoading = true;
    try {
      const base = `${this.api}/${encodeURIComponent(this.selectedId)}`;
      this.tabData = tab === 'queries' && this.groupBy === 'order'
        ? await this.fetchJson(`${base}/rows/queries?limit=500`)
        : tab === 'queries'
        ? await this.fetchJson(`${base}/queries?group_by=${this.groupBy}&limit=200`)
        : tab === 'trace'
          ? await this.fetchJson(`${base}/trace`)
          : await this.fetchJson(`${base}/rows/${encodeURIComponent(tab)}?limit=100`);
    } catch (e) {
      this.error = e.message;
    } finally {
      this.tabLoading = false;
    }
  },

  async loadMore() {
    if (this.tabData?.next_offset == null) return;
    const limit = this.tab === 'queries' ? 500 : 100;
    const more = await this.fetchJson(`${this.api}/${encodeURIComponent(this.selectedId)}/rows/${encodeURIComponent(this.tab)}?limit=${limit}&offset=${this.tabData.next_offset}`);
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

  get tabs() {
    if (!this.capture) return [];
    const counts = this.capture.summary?.counts ?? {};
    const tabs = [{ key: 'overview', label: 'Overview' }, { key: 'timeline', label: 'Timeline' }];
    const has = (key) => (this.capture.available_sections ?? []).some((s) => s.key === key);
    if (has('request')) tabs.push({ key: 'request', label: 'Request' });
    if (has('session')) tabs.push({ key: 'session', label: 'Session' });
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

  get totals() {
    const totals = this.summary.totals ?? {};
    const shown = (k, v) => v !== null && typeof v !== 'object' && (v !== 0 || k === 'queries');
    const scalar = Object.entries(totals).filter(([k, v]) => shown(k, v));
    const first = KEY_TOTALS.filter((k) => totals[k] !== undefined && shown(k, totals[k])).map((k) => [k, totals[k]]);
    return [...first, ...scalar.filter(([k]) => !KEY_TOTALS.includes(k))];
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
    if (key === 'duration' && typeof v === 'number') return esc(ms(v));
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
  totalLabel(k) {
    const labels = { duration_ms: 'Duration', query_ms: 'Query time', cache_ms: 'Cache time', redis_ms: 'Redis time', views_ms: 'View time', middleware_self_ms: 'Middleware time', memory_peak_mb: 'Peak memory', response_bytes: 'Response size', cache_hit_rate: 'Cache hit rate' };
    return labels[k] ?? humanize(k.replace(/_(ms|mb|bytes)$/, ''));
  },
  originHtml(origin) { return origin?.file ? maybeLink(origin.file + (origin.line ? ':' + origin.line : ''), origin.link, cls.origin) : ''; },
}));

Alpine.plugin(collapse);
window.Alpine = Alpine;
Alpine.start();
