import '../css/dashboard.css';
import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import { esc, ms, ago, humanize, fmtValue, maybeLink, classes, copyButton, isThrowable, exceptionHtml, previousHtml, usePathMappings } from './format.js';
import { cls, statusClass, httpStatusClass } from './classes.js';
import { indexTree, renderTimeline, phasesOf, LENS_COLOR } from './timeline.js';
import { setupPopover, rowDetails, rowBody, originHtml as originBlock } from './popover.js';
import { setupTooltip } from './tooltip.js';
import * as values from './render/values.js';
import * as cells from './render/cells.js';
import * as details from './render/details.js';
import * as totals from './render/totals.js';

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


const SKIP_SECTIONS = ['summary', 'timeline', 'request', 'session', 'meta', 'queries', 'model_changes'];
/** Section tabs, most useful first; sections not listed follow in their own order. */

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
  customDetails: {},
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
          : await this.fetchJson(`${base}/rows/${encodeURIComponent(tab)}?limit=${this.presentationOf(tab)?.groupings?.length ? 500 : 100}&verbose=1`);
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
    const problems = this.capture.summary?.problems ?? {};
    const tabs = [{ key: 'overview', label: 'Overview' }, { key: 'timeline', label: 'Timeline' }];
    const has = (key) => (this.capture.available_sections ?? []).some((s) => s.key === key && s.rows !== 0);
    const isRequest = this.summary.type === 'request';
    if (isRequest && has('request')) tabs.push({ key: 'request', label: 'Request', problems: problems.request ?? 0 });
    if (isRequest && has('session')) tabs.push({ key: 'session', label: 'Session' });
    const skip = isRequest ? [...SKIP_SECTIONS, 'auth'] : SKIP_SECTIONS;
    if (counts.queries) tabs.push({ key: 'queries', label: 'Queries', count: counts.queries, problems: problems.queries ?? 0 });
    const sections = (this.capture.available_sections ?? []).filter((s) => !skip.includes(s.key) && s.rows);
    // Model changes are shown in the Models tab, under the counts.
    const changes = (this.capture.available_sections ?? []).find((s) => s.key === 'model_changes' && s.rows);
    if (changes && !sections.some((s) => s.key === 'models')) sections.push({ ...changes, key: 'models', rows: 0 });
    // Section tabs follow monocle.dashboard.tabs; sections not listed follow in their own order.
    const order = this.capture.tab_order ?? [];
    const rank = (key) => { const i = order.indexOf(key); return i === -1 ? order.length : i; };
    for (const s of sections.sort((a, b) => rank(a.key) - rank(b.key))) {
      tabs.push({ key: s.key, label: this.capture.presentations?.[s.key]?.label ?? humanize(s.key), count: s.rows || changes?.rows, description: s.description, problems: problems[s.key] ?? 0 });
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
    // Totals of other lenses group under their section's tab when they start with its key (payments_charged).
    for (const tab of this.tabs) {
      const items = Object.entries(totals).filter(([k, v]) => !taken.has(k) && (k === tab.key || k.startsWith(tab.key + '_')) && shown(k, v));
      items.forEach(([k]) => taken.add(k));
      if (items.length) groups.push({ label: tab.label, items });
    }
    groups.push({ label: 'Other', items: Object.entries(totals).filter(([k, v]) => !taken.has(k) && shown(k, v)) });
    return groups.filter((g) => g.items.length);
  },


  /** Original query row number => how many later queries repeat it. */
  get duplicates() {
    const counts = {};
    for (const q of this.tabData?.rows ?? []) if (q.duplicate_of) counts[q.duplicate_of] = (counts[q.duplicate_of] ?? 0) + 1;
    return counts;
  },





  /** A value from the open assoc section, linked when the server found an editor link for its path. */
  linked(value, path) {
    if (value === null || (Array.isArray(value) && !value.length)) return `<span class="text-muted">${value === null ? 'null' : '[]'}</span>`;
    const link = this.tabData?.links?.[path];
    return link ? maybeLink(fmtValue(value), link) : classes(fmtValue(value));
  },


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
    return 'monocle-panel-' + (['overview', 'timeline', 'trace', ...config.tabs].includes(key) ? key : 'rows');
  },

  /** Arrow keys move between tabs, as in any tab list. */
  stepTab(direction) {
    const keys = this.tabs.map((t) => t.key);
    const next = keys[(keys.indexOf(this.tab) + direction + keys.length) % keys.length];
    this.openTab(next);
    this.$nextTick(() => document.getElementById('monocle-tab-' + next)?.focus());
  },

  linkHtml(text, link) { return text ? maybeLink(text, link) : ''; },


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


  get tables() {
    if (this.groups.length) return [];
    const rows = this.tabData?.rows ?? [];
    const own = { key: this.tab, title: null, rows: this.tab === 'log' && this.logLevels.length ? rows.filter((row) => this.logLevels.includes(row.level)) : rows };
    return this.tab === 'models' ? [own, { key: 'model_changes', title: 'Changes', rows: this.modelChanges }] : [own];
  },

  get tableColumns() {
    return this.columnsOf(this.tabData?.rows ?? [], this.tab);
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


  toggleQuery(n) {
    this.openQueries = this.openQueries.includes(n) ? this.openQueries.filter((k) => k !== n) : [...this.openQueries, n];
  },


  /** The groupings the open section offers, and the one picked. */
  get groupings() { return this.presentationOf(this.tab)?.groupings ?? []; },
  get rowGroup() { return this.rowGroups[this.tab] ?? 'order'; },
  setRowGroup(by) { this.rowGroups = { ...this.rowGroups, [this.tab]: by }; this.openGroups = []; },

  /** Rows of the open section grouped by the picked grouping: count, failures and timing per group. */
  get groups() {
    const by = this.rowGroup;
    if (by === 'order' || !this.groupings.length) return [];
    const keyOf = (row) => (row[by] == null || row[by] === '' ? '(none)' : typeof row[by] === 'object' ? JSON.stringify(row[by]) : String(row[by]));
    const failed = (row) => row.failed === true || row.ok === false || row.passed === false || row.allowed === false || (typeof row.status === 'number' && row.status >= 400);
    const groups = new Map();
    (this.tabData?.rows ?? []).forEach((row, i) => {
      const key = keyOf(row);
      // The group shows its value the way the table cell would (a coloured status, #1 for a pool).
      const g = groups.get(key) ?? { key, html: this.cell(row, by) || '<span class="text-muted">(none)</span>', count: 0, failed: 0, total_ms: 0, max_ms: 0, origin: row.origin, rows: [] };
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
    const skip = [this.rowGroup, 'duration', 'time', 'origin'];
    const columns = this.columnsOf(this.tabData?.rows ?? [], this.tab).filter((c) => !skip.includes(c) && !this.narrow(c));
    return [row.method, ...columns.slice(0, row.method ? 1 : 2).map((c) => fmtValue(row[c]))].filter(Boolean).join(' · ');
  },





  toggleRow(key) {
    this.openRows = this.openRows.includes(key) ? this.openRows.filter((k) => k !== key) : [...this.openRows, key];
  },

  toggleGroup(key) {
    this.openGroups = this.openGroups.includes(key) ? this.openGroups.filter((k) => k !== key) : [...this.openGroups, key];
  },









  // Rendering helpers, used by the templates.
  ...values,
  ...cells,
  ...details,
  ...totals,

  /** How a lens wants its section shown, when it says (Presentable lenses). */
  presentationOf(section) { return this.capture?.presentations?.[section] ?? null; },
  columnsOf(rows, section = null) { return cells.columnsOf(rows, section, this.presentationOf(section)); },
  columnLabel(col, section = null) { return cells.columnLabel(col, this.presentationOf(section)); },

  /** An expanded row: rendered by the lens's own view when it has one, fetched once. */
  details(row, section) {
    if (!this.presentationOf(section)?.details_view) return details.details(row, section);
    const n = row.n ?? (this.tabData?.rows ?? []).indexOf(row) + 1;
    const key = `${this.selectedId}:${section}:${n}`;
    if (!(key in this.customDetails)) {
      this.customDetails[key] = '<p class="text-xs text-muted">Loading...</p>';
      this.fetchJson(`${this.api}/${encodeURIComponent(this.selectedId)}/rows/${encodeURIComponent(section)}/${n}/details`)
        .then((result) => { this.customDetails = { ...this.customDetails, [key]: values.hydrate(result.html) }; })
        .catch((e) => { this.customDetails = { ...this.customDetails, [key]: `<p class="text-xs text-bad">${esc(e.message)}</p>` }; });
    }
    return this.customDetails[key];
  },

  cls, ms, ago, statusClass, humanize, esc, maybeLink, rowBody, classes,
  phaseColor(name) { return PHASE_COLORS[name] ?? 'var(--c-phase)'; },
  categoryColor(name) { return CATEGORY_COLORS[name] ?? LENS_COLOR[name] ?? 'var(--c-phase)'; },

  originHtml(origin) { return origin?.file ? maybeLink(origin.file + (origin.line ? ':' + origin.line : ''), origin.link, cls.origin) : ''; },
}));

Alpine.plugin(collapse);
window.Alpine = Alpine;
Alpine.start();
