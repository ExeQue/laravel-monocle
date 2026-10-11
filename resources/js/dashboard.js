import '../css/dashboard.css';
import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import { esc, ms, ago, humanize, fmtValue, maybeLink, classes, copyButton, isThrowable, exceptionHtml, previousHtml, usePathMappings } from './format.js';
import { cls, statusClass, httpStatusClass } from './classes.js';
import { indexTree, renderTimeline, phasesOf, LENS_COLOR } from './timeline.js';
import { setupPopover, rowDetails, rowBody, originHtml as originBlock } from './popover.js';
import { setupTooltip } from './tooltip.js';

const PHASE_COLORS = {
  bootstrap: 'var(--c-phase)', 'middleware (request)': 'var(--c-mw)', routing: 'var(--c-phase)', controller: 'var(--c-ctrl)', command: 'var(--c-ctrl)', job: 'var(--c-ctrl)',
  'middleware (response)': 'color-mix(in srgb, var(--c-mw) 75%, var(--panel))', response: 'var(--c-phase)', terminate: 'var(--c-phase)', other: 'var(--line)',
};
const CATEGORY_COLORS = { db: 'var(--c-db)', cache: 'var(--c-cache)', redis: 'var(--c-io)', views: 'var(--c-view)', http: 'var(--c-io)', filesystem: 'var(--c-io)', ai: 'var(--c-ctrl)', jobs_sync: 'var(--c-mw)' };

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
  ['Files', (k) => k.startsWith('filesystem_') || k.startsWith('media_')],
  ['Mail', (k) => k.startsWith('mail') || k.startsWith('notification')],
  ['Auth', (k) => k.startsWith('auth_')],
  ['Validation', (k) => k.startsWith('validation')],
  ['Features', (k) => k.startsWith('features_')],
  ['Authorization', (k) => k.startsWith('authorization_')],
];
/** Table columns whose values are worth copying: keys, urls, names, paths, messages. */
const COPY_COLUMNS = ['key', 'keys', 'url', 'path', 'destination', 'view', 'name', 'class', 'message', 'command', 'job', 'event', 'listener', 'ability', 'subject', 'to', 'from', 'tool', 'model', 'feature', 'component', 'prompt', 'response', 'text', 'file', 'id', 'uuid', 'job_id', 'batch_id', 'value', 'sql'];

/** How each section's rows can be grouped, besides in order: [key, label]. */
const GROUPINGS = {
  http: [['endpoint', 'by endpoint'], ['host', 'by host'], ['pool', 'by pool'], ['status', 'by status']],
  views: [['view', 'by view'], ['engine', 'by engine']],
  cache: [['key', 'by key'], ['type', 'by operation'], ['store', 'by store']],
  events: [['event', 'by event']],
  filesystem: [['path', 'by path'], ['op', 'by operation'], ['disk', 'by disk']],
  jobs: [['job', 'by job'], ['queue', 'by queue']],
  mail: [['type', 'by type']],
  authorization: [['ability', 'by ability'], ['allowed', 'by outcome']],
  exceptions: [['class', 'by exception']],
  features: [['feature', 'by feature']],
  redis: [['command', 'by command']],
  log: [['message', 'by message']],
};

const SKIP_SECTIONS = ['summary', 'timeline', 'request', 'session', 'meta', 'queries', 'model_changes'];
/** Section tabs, most useful first; sections not listed follow in their own order. */
const TAB_ORDER = ['exceptions', 'validation', 'response_cache', 'auth', 'features', 'cache', 'views', 'http', 'ai', 'filesystem', 'media', 'jobs', 'livewire', 'models', 'model_changes', 'mail', 'log', 'dumps', 'redis', 'events', 'authorization', 'middleware', 'mcp', 'scheduled_tasks'];
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
const changesTable = (changes) => `<table class="w-full table-fixed border-collapse text-xs"><colgroup><col class="w-[28%]"><col class="w-[36%]"><col class="w-[36%]"></colgroup><thead><tr>${['Field', 'From', 'To'].map((h) => `<th scope="col" class="px-1.5 py-0.5 text-left font-medium text-muted">${h}</th>`).join('')}</tr></thead><tbody>${changes.map((c) => `<tr class="border-t border-line"><td class="px-1.5 py-0.5 align-top font-mono [overflow-wrap:anywhere]">${esc(c.field)}</td><td class="px-1.5 py-0.5 align-top font-mono text-bad [overflow-wrap:anywhere]">${c.from === null ? '<span class="text-muted">null</span>' : esc(fmtValue(c.from))}</td><td class="px-1.5 py-0.5 align-top font-mono text-ok [overflow-wrap:anywhere]">${c.to === null ? '<span class="text-muted">null</span>' : esc(fmtValue(c.to))}</td></tr>`).join('')}</tbody></table>`;

Alpine.data('monocle', (config) => (usePathMappings(config.paths), {
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
  authRows: [],
  openGroups: [],
  openQueries: [],
  queryRows: {},
  tabData: null,
  tabLoading: false,
  groupBy: 'order',
  rowGroups: {},
  modelChanges: [],
  logLevels: [],
  openRows: [],
  timelineState: { open: new Set(), shut: new Set(), kinds: new Set() },
  timelineHtml: '',
  foldable: [],
  theme: theme.get(),
  error: null,
  confirmClear: false,
  listOpen: false,
  copied: null,
  status: '',
  responseView: 'pretty',
  wide: true,

  init() {
    const wide = matchMedia('(min-width: 861px)');
    this.wide = wide.matches;
    wide.addEventListener('change', (e) => { this.wide = e.matches; });
    this.popover = setupPopover(this.$refs.detail, () => this.model);
    setupTooltip();
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
      // A filtered list hides captures on purpose; only an unfiltered or empty one means it may be gone.
      const filtered = this.filters.type || this.filters.q || this.filters.errors || this.hidden.length;
      if (this.selectedId && (!filtered || !this.captures.length) && !this.captures.some((c) => c.id === this.selectedId)) await this.dropIfGone();
      if (!this.selectedId && this.captures[0]) this.select(this.captures[0].id);
      else if (!quiet && this.selectedId && !this.capture) this.select(this.selectedId, this.tab);
    } catch (e) {
      this.error = e.message;
    }
  },

  /** Close the open capture once it is no longer stored (cleared, or pushed out of the ring buffer). */
  async dropIfGone() {
    const response = await fetch(`${this.api}/${encodeURIComponent(this.selectedId)}`, { method: 'GET', headers: { Accept: 'application/json' } });
    if (response.status !== 404) return;
    this.popover.close();
    this.selectedId = null;
    this.capture = null;
    this.model = null;
    this.tabData = null;
    this.status = 'The open capture is no longer stored';
    history.replaceState(null, '', location.pathname + location.search);
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
      this.status = `Showing ${capture.summary?.name ?? 'capture'}`;
      this.model = { tree: indexTree(capture.timeline), duration: capture.summary?.totals?.duration_ms ?? this.duration(capture) };
      if (!['overview', 'timeline'].includes(this.tab) && !this.tabs.some((t) => t.key === this.tab)) this.tab = 'overview';
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
      this.openGroups = [];
      this.openQueries = [];
      this.openRows = [];
      this.logLevels = [];
      this.queryRows = tab === 'queries' && this.groupBy !== 'order'
        ? Object.fromEntries(((await this.fetchJson(`${base}/rows/queries?limit=500&verbose=1`)).rows ?? []).map((row) => [row.n, row]))
        : {};
      this.modelChanges = tab === 'models'
        ? (await this.fetchJson(`${base}/rows/model_changes?limit=500&verbose=1`)).rows ?? []
        : [];
      this.authRows = tab === 'request' && this.summary.counts?.auth
        ? (await this.fetchJson(`${base}/rows/auth?limit=100&verbose=1`)).rows ?? []
        : [];
      this.tabData = tab === 'queries' && this.groupBy === 'order'
        ? await this.fetchJson(`${base}/rows/queries?limit=500&verbose=1`)
        : tab === 'queries'
        ? await this.fetchJson(`${base}/queries?group_by=${this.groupBy}&limit=200`)
        : tab === 'trace'
          ? await this.fetchJson(`${base}/trace`)
          : await this.fetchJson(`${base}/rows/${encodeURIComponent(tab)}?limit=${tab === 'http' ? 500 : 100}&verbose=1`);
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
    const skip = isRequest ? [...SKIP_SECTIONS, 'auth'] : SKIP_SECTIONS;
    if (counts.queries) tabs.push({ key: 'queries', label: 'Queries', count: counts.queries });
    const sections = (this.capture.available_sections ?? []).filter((s) => !skip.includes(s.key) && s.rows);
    // Model changes are shown in the Models tab, under the counts.
    const changes = (this.capture.available_sections ?? []).find((s) => s.key === 'model_changes' && s.rows);
    if (changes && !sections.some((s) => s.key === 'models')) sections.push({ ...changes, key: 'models', rows: 0 });
    for (const s of sections.sort((a, b) => rank(a.key) - rank(b.key))) {
      tabs.push({ key: s.key, label: humanize(s.key), count: s.rows || changes?.rows, description: s.description });
    }
    if (this.summary.parent_id || this.summary.children?.length) tabs.push({ key: 'trace', label: 'Trace', count: this.summary.children?.length || undefined });
    return tabs;
  },

  get summary() { return this.capture?.summary ?? {}; },
  get phases() { return (this.summary.breakdown?.phases ?? []).filter((p) => p.name !== 'other' || p.ms >= 0.05); },
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
    if (v === null || (Array.isArray(v) && !v.length)) return `<span class="text-muted">${v === null ? 'null' : '[]'}</span>`;
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
    if (value === null || (Array.isArray(value) && !value.length)) return `<span class="text-muted">${value === null ? 'null' : '[]'}</span>`;
    const link = this.tabData?.links?.[path];
    return link ? maybeLink(fmtValue(value), link) : classes(fmtValue(value));
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
    this.status = what === 'id' ? 'Capture id copied' : 'Agent prompt copied';
    setTimeout(() => { if (this.copied === what) this.copied = null; }, 1500);
  },

  /** A prompt for the coding agent to look into this capture through the Monocle MCP server. */
  get agentPrompt() {
    const s = this.summary;
    const lines = [
      `Use the Monocle MCP server to look into capture ${this.capture.id}: ${s.name} (${s.type}${s.status != null ? `, status ${s.status}` : ''}, ${ms(s.totals?.duration_ms)}).`,
      `Start with monocle-show id=${this.capture.id}, then dig into the sections that matter with monocle-rows and monocle-queries.`,
    ];
    const ctx = this.capture.context ?? {};
    const handledBy = [ctx.route && `route ${ctx.route}`, ctx.action ?? ctx.command_class].filter(Boolean).join(', ');
    if (handledBy) lines.splice(1, 0, `Handled by ${handledBy}.`);
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

  panelId(key) {
    return 'monocle-panel-' + (['overview', 'timeline', 'request', 'session', 'queries', 'trace'].includes(key) ? key : 'rows');
  },

  /** Arrow keys move between tabs, as in any tab list. */
  stepTab(direction) {
    const keys = this.tabs.map((t) => t.key);
    const next = keys[(keys.indexOf(this.tab) + direction + keys.length) % keys.length];
    this.openTab(next);
    this.$nextTick(() => document.getElementById('monocle-tab-' + next)?.focus());
  },

  linkHtml(text, link) { return text ? maybeLink(text, link) : ''; },

  /** One item of a response capture card. */
  cardValue(value, key) {
    if (Array.isArray(value) && value.length && value.every((c) => c && typeof c === 'object' && 'field' in c && 'from' in c && 'to' in c)) return changesTable(value);
    if (key.endsWith('_bytes') && typeof value === 'number') return esc(this.totalValue([key, value]));
    if (Array.isArray(value) && value.length && value.every((v) => v && typeof v === 'object' && !Array.isArray(v))) {
      const cols = [...new Set(value.flatMap((v) => Object.keys(v)))];
      const cell = (v) => (v === null || v === undefined ? '<span class="text-muted">-</span>' : esc(typeof v === 'object' ? JSON.stringify(v) : String(v)));
      return `<table class="w-full border-collapse text-xs"><thead><tr>${cols.map((c) => `<th scope="col" class="px-1.5 py-0.5 text-left font-medium text-muted">${esc(humanize(c))}</th>`).join('')}</tr></thead><tbody>${value.map((row) => `<tr class="border-t border-line">${cols.map((c) => `<td class="px-1.5 py-0.5 align-top [overflow-wrap:anywhere]">${cell(row[c])}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
    }
    if (key === 'body' && typeof value === 'string') return `<pre class="m-0 max-h-96 overflow-auto whitespace-pre-wrap">${esc(value)}</pre>`;
    if (Array.isArray(value) && value.every((v) => v === null || typeof v !== 'object')) return value.map((v) => `<div>${esc(fmtValue(v))}</div>`).join('');
    if (value && typeof value === 'object') return this.dot(value).map(([k, v]) => `<div><span class="text-muted">${esc(k)}</span> ${esc(fmtValue(v))}</div>`).join('');
    return esc(fmtValue(value));
  },

  get request() { return this.tab === 'request' ? (this.tabData?.data ?? null) : null; },
  get session() { return this.tab === 'session' ? (this.tabData?.data ?? null) : null; },

  /** The tables in a generic tab: the tab's own rows, and model changes under the model counts. */
  /** The log levels in the open log, most severe first, with how many entries each has. */
  get levelCounts() {
    if (this.tab !== 'log') return [];
    const order = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];
    const counts = {};
    (this.tabData?.rows ?? []).forEach((row) => { counts[row.level] = (counts[row.level] ?? 0) + 1; });
    return Object.entries(counts).sort(([a], [b]) => order.indexOf(a) - order.indexOf(b));
  },

  toggleLevel(level) {
    this.logLevels = this.logLevels.includes(level) ? this.logLevels.filter((l) => l !== level) : [...this.logLevels, level];
  },

  levelClass(level) {
    return { debug: 'text-muted', info: 'text-[var(--c-http)]', notice: 'text-[var(--c-http)]', warning: 'text-warn', error: 'text-bad', critical: 'text-bad', alert: 'text-bad', emergency: 'text-bad' }[level] ?? '';
  },

  get tables() {
    if (this.groups.length) return [];
    const rows = this.tabData?.rows ?? [];
    const own = { key: this.tab, title: null, rows: this.tab === 'log' && this.logLevels.length ? rows.filter((row) => this.logLevels.includes(row.level)) : rows };
    return this.tab === 'models' ? [own, { key: 'model_changes', title: 'Changes', rows: this.modelChanges }] : [own];
  },

  get tableColumns() {
    return this.columnsOf(this.tabData?.rows ?? [], this.tab);
  },

  columnsOf(rows, section = null) {
    const keys = [];
    const detailOnly = ['n', '_links', 'frames', 'request_headers', 'response_headers', 'request_body', 'response_body', 'request_payload', 'response_capture', ...(section === 'http' ? ['exception', 'failed', 'timing'] : []), ...(section === 'validation' ? ['input'] : [])];
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
  },

  /** Text with the given substrings turned into editor links. */
  linkText(text, links = {}) {
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
  },

  /** The user the request ran as, from the auth section. */
  get authUser() {
    const row = [...this.authRows].reverse().find((r) => ['authenticated', 'login', 'token'].includes(r.event) && (r.user || r.owner));
    if (!row) return null;
    const user = row.user ?? row.owner;
    return { user, link: row._links?.user ?? row._links?.owner, via: row.event === 'token' ? `Sanctum token ${row.token_name ?? row.token_id}` : `${row.guard} guard` };
  },

  /** Auth events worth listing next to the request: attempts, failures, logins, lockouts. */
  get authEvents() {
    return this.authRows.filter((r) => r.event !== 'authenticated');
  },

  /** Values long enough to need breaking anywhere, like keys, SQL and paths. */
  longValue(value) {
    return typeof value === 'string' ? value.length > 24 : value !== null && typeof value === 'object';
  },

  toggleQuery(n) {
    this.openQueries = this.openQueries.includes(n) ? this.openQueries.filter((k) => k !== n) : [...this.openQueries, n];
  },

  /** Everything recorded about one query: its bindings, where it ran and how it was called. */
  queryDetails(q) {
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
  },

  /** The groupings the open section offers, and the one picked. */
  get groupings() { return GROUPINGS[this.tab] ?? []; },
  get rowGroup() { return this.rowGroups[this.tab] ?? 'order'; },
  setRowGroup(by) { this.rowGroups = { ...this.rowGroups, [this.tab]: by }; this.openGroups = []; },

  /** Rows of the open section grouped by the picked grouping: count, failures and timing per group. */
  get groups() {
    const by = this.rowGroup;
    if (by === 'order' || !this.groupings.length) return [];
    const host = (url) => { try { return new URL(url).host; } catch { return '?'; } };
    const keyOf = {
      endpoint: (row) => `${row.method} ${String(row.url ?? '').split('?')[0].replace(/\/(\d+|[0-9a-f-]{32,36}|[0-9a-f]{24,})(?=\/|$)/gi, '/{id}')}`,
      host: (row) => host(row.url),
      pool: (row) => (row.pool == null ? 'not pooled' : `pool #${row.pool}`),
      status: (row) => (row.status == null ? 'no response' : String(row.status)),
      allowed: (row) => (row.allowed ? 'allowed' : 'denied'),
    }[this.tab === 'http' || by === 'allowed' ? by : null] ?? ((row) => (row[by] == null || row[by] === '' ? '(none)' : typeof row[by] === 'object' ? JSON.stringify(row[by]) : String(row[by])));
    const failed = (row) => row.failed === true || row.ok === false || row.passed === false || row.allowed === false || (typeof row.status === 'number' && row.status >= 400);
    const groups = new Map();
    (this.tabData?.rows ?? []).forEach((row, i) => {
      const key = keyOf(row);
      const g = groups.get(key) ?? { key, count: 0, failed: 0, total_ms: 0, max_ms: 0, origin: row.origin, rows: [] };
      const ms = Number(row.duration ?? 0);
      g.count++;
      g.failed += failed(row) ? 1 : 0;
      g.total_ms += ms;
      g.max_ms = Math.max(g.max_ms, ms);
      g.rows.push(i);
      groups.set(key, g);
    });
    return [...groups.values()].map((g) => ({ ...g, avg_ms: g.total_ms / g.count })).sort((a, b) => b.total_ms - a.total_ms || b.count - a.count);
  },

  /** A row inside a group, in one line: the columns that tell it apart from the rest of its group. */
  memberLabel(row) {
    if (this.tab === 'http') return `${row.method} ${row.url}`;
    const skip = [this.rowGroup, 'duration', 'time', 'origin'];
    return this.columnsOf(this.tabData?.rows ?? [], this.tab).filter((c) => !skip.includes(c)).slice(0, 2).map((c) => fmtValue(row[c])).filter(Boolean).join(' · ');
  },

  /** The details of an expanded row: HTTP calls get their own layout, other rows list every field. */
  details(row, section) {
    if (section === 'http') return this.httpDetails(row);
    if (section === 'validation') return this.validationDetails(row);
    return rowBody(row);
  },

  /** An outgoing HTTP call: facts, both header sets, the payload sent and the response received. */
  httpDetails(row) {
    const label = 'text-[11px] uppercase tracking-wider text-muted';
    const td = 'border-t border-line px-2 py-1 align-top font-mono text-xs [overflow-wrap:anywhere]';
    const kvTable = (entries, what) => entries.length
      ? `<table class="w-full border-collapse">${entries.map(([k, v]) => `<tr class="group/copy"><td class="${td} w-0 whitespace-nowrap text-muted">${esc(k)}</td><td class="${td}">${this.payloadValue(v)}</td><td class="${td} w-0">${v === null ? '' : copyButton(typeof v === 'object' ? JSON.stringify(v) : String(v), `${what} ${k}`, { hover: true })}</td></tr>`).join('')}</table>`
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
    const cards = (capture?.cards ?? []).map((card) => `<div class="grid gap-1 rounded-md border border-line bg-panel p-2.5"><div><b class="text-[13px]">${esc(card.title)}</b>${card.subtitle ? ` <span class="text-xs text-muted">${esc(card.subtitle)}</span>` : ''}</div><dl class="m-0 grid grid-cols-[max-content_minmax(0,1fr)] gap-x-3 gap-y-1 text-xs">${Object.entries(card.items ?? {}).map(([k, v]) => `<dt class="text-muted">${esc(humanize(k))}</dt><dd class="m-0 font-mono [overflow-wrap:anywhere]">${this.cardValue(v, k)}</dd>`).join('')}</dl></div>`).join('');
    const raw = capture?.raw ?? row.response_body;
    const type = this.headers(row.response_headers).find(([k]) => k.toLowerCase() === 'content-type')?.[1];
    const pre = (text) => `<pre class="scroll-quiet m-0 max-h-80 overflow-auto rounded-md border border-line bg-panel p-2.5 font-mono text-xs whitespace-pre-wrap [overflow-wrap:anywhere]">${esc(this.body(text, type))}</pre>`;
    const response = cards
      ? `${cards}${raw ? `<details><summary class="cursor-pointer text-xs text-muted">Raw body</summary><div class="mt-1.5">${pre(raw)}</div></details>` : ''}`
      : raw ? pre(raw) : '<span class="text-xs text-muted">not recorded</span>';

    return `<div class="grid gap-4">
      <div class="flex flex-wrap items-start gap-1.5 font-mono text-xs"><b>${esc(row.method ?? '')}</b><span class="[overflow-wrap:anywhere]">${esc(row.url ?? '')}</span>${copyButton(row.url ?? '', 'url')}</div>
      <dl class="m-0 grid grid-cols-[repeat(auto-fill,minmax(110px,1fr))] gap-3">${facts.map(([k, v, tone]) => `<div class="grid gap-0.5"><dt class="${label}">${k}</dt><dd class="m-0 text-[13px] tabular-nums [overflow-wrap:anywhere] ${tone ?? ''}">${esc(String(v))}</dd></div>`).join('')}</dl>
      ${row.timing ? block('Timing', this.httpTiming(row.timing)) : ''}
      ${row.exception ? block('Exception', `<div class="rounded-md border border-line bg-panel p-2.5 text-xs">${exceptionHtml(row.exception, { full: true })}</div>`, [row.exception, ...(row.exception.previous ?? [])].map((x) => `${x.class}: ${x.message}`).join('\n')) : ''}
      <div class="grid gap-4 lg:grid-cols-2">
        ${block('Request headers', kvTable(this.headers(row.request_headers), 'request header'))}
        ${block('Response headers', kvTable(this.headers(row.response_headers), 'response header'))}
      </div>
      <div class="grid gap-4 lg:grid-cols-2">
        ${block('Payload', row.request_payload ? kvTable(this.dot(row.request_payload), 'payload') : row.request_body ? pre(row.request_body) : '<span class="text-xs text-muted">none</span>', row.request_payload ? JSON.stringify(row.request_payload) : row.request_body)}
        ${block('Response', response, raw)}
      </div>
      ${originBlock(row.origin)}
    </div>`;
  },

  /** One validation: a row per field with its rules, the input and what failed, then the rest. */
  validationDetails(row) {
    const td = 'border-t border-line px-2 py-1.5 align-top';
    const failed = {};
    (row.failed ?? []).forEach((f) => { (failed[f.field] ??= []).push(f); });
    const fields = [...new Set([...Object.keys(row.rules ?? {}), ...Object.keys(failed)])];
    const size = (v) => (v >= 1048576 ? (v / 1048576).toFixed(1) + ' MB' : v >= 1024 ? (v / 1024).toFixed(1) + ' KB' : v + ' B');
    const input = (field) => {
      const v = this.dot(row.input ?? {}).find(([k]) => k === field)?.[1] ?? (row.input ?? {})[field.split('.')[0]];
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
  },

  /** Where the time of an HTTP call went: one bar split into its phases, then the connection facts. */
  httpTiming(timing) {
    const phases = [['dns', 'DNS', 'var(--c-cache)'], ['connect', 'Connect', 'var(--c-db)'], ['tls', 'TLS', 'var(--c-view)'], ['wait', 'Waiting', 'var(--c-http)'], ['download', 'Download', 'var(--c-ctrl)'], ['redirect', 'Redirects', 'var(--c-phase)']]
      .filter(([k]) => typeof timing[k] === 'number');
    const total = phases.reduce((sum, [k]) => sum + timing[k], 0) || 1;
    const facts = [['Remote', timing.remote], ['Protocol', timing.protocol], ['Redirects followed', timing.redirects]].filter(([, v]) => v);
    return `<div class="grid gap-2 text-xs">
      <div class="flex h-2.5 overflow-hidden rounded-sm bg-sunk" role="img" aria-label="${esc(phases.map(([k, l]) => `${l} ${ms(timing[k])}`).join(', '))}">${phases.map(([k, , c]) => `<span style="width:${(timing[k] / total) * 100}%;background:${c}"></span>`).join('')}</div>
      <div class="flex flex-wrap gap-x-4 gap-y-1">${phases.map(([k, l, c]) => `<span class="inline-flex items-center gap-1.5"><i class="size-2 rounded-sm" style="background:${c}"></i><span class="text-muted">${l}</span><span class="tabular-nums">${ms(timing[k])}</span></span>`).join('')}</div>
      ${facts.length ? `<div class="flex flex-wrap gap-x-4 gap-y-1">${facts.map(([l, v]) => `<span><span class="text-muted">${l}</span> <span class="font-mono">${esc(String(v))}</span></span>`).join('')}</div>` : ''}
    </div>`;
  },

  toggleRow(key) {
    this.openRows = this.openRows.includes(key) ? this.openRows.filter((k) => k !== key) : [...this.openRows, key];
  },

  toggleGroup(key) {
    this.openGroups = this.openGroups.includes(key) ? this.openGroups.filter((k) => k !== key) : [...this.openGroups, key];
  },

  /** A name cut to 32 characters, for header and cookie names; the full name is in a tooltip. */
  shortName(name) {
    return name.length > 32 ? name.slice(0, 32) + '…' : name;
  },

  /** A column header: `time` is an offset into the capture, not a duration. */
  columnLabel(col) {
    if (col === 'time') return 'Started at';
    if (col.endsWith('_bytes') && !col.includes('size')) return humanize(col.replace(/_bytes$/, '')) + ' size';
    return humanize(col.replace(/_(bytes|ms)$/, ''));
  },

  /** Columns that only hold a short word or code and take as little width as they can. */
  narrow(key) {
    return ['method', 'status'].includes(key);
  },

  numeric(key) {
    return ['count', 'duration', 'time', 'size', 'bytes', 'n', 'attempts', 'status', 'ttl'].includes(key) || /_(ms|bytes|count)$/.test(key);
  },

  cell(row, key) {
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
    if (key === 'level' && typeof v === 'string') return `<span class="font-medium ${this.levelClass(v)}">${esc(v)}</span>`;
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
  },

  // Template helpers
  cls, ms, ago, statusClass, humanize, esc, maybeLink, copyButton, rowBody, classes,
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
