import '../css/dashboard.css';
import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import { esc, ms, ago, shortType, humanize, fmtValue, maybeLink, nameLink, classes, copyButton, isThrowable, exceptionHtml, previousHtml, usePathMappings } from './format.js';
import { cls, statusClass, httpStatusClass, tagClass } from './classes.js';
import { indexTree, renderTimeline, phasesOf, LENS_COLOR } from './timeline.js';
import { setupPopover, rowDetails, rowBody, originHtml as originBlock } from './popover.js';
import { setupTooltip } from './tooltip.js';
import { setupDragScroll } from './drag-scroll.js';
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


/**
 * How well `query` matches `text` when its letters appear in order ("qrs" in "Queries"):
 * lower is closer (a tighter, earlier run of letters), null when they do not all appear.
 */
const fuzzyScore = (query, text) => {
  let at = -1;
  let first = -1;
  for (const char of query) {
    at = text.indexOf(char, at + 1);
    if (at === -1) return null;
    if (first === -1) first = at;
  }
  return (at - first) + first / 10;
};

/** The lowest of some scores, ignoring nulls; null when there are none. */
const lowest = (scores) => scores.reduce((low, score) => (score === null || (low !== null && low <= score) ? low : score), null);

/**
 * A search as a function scoring an entry ({ label, route, status, kind, sections }), lower
 * is closer and null is no match; null itself when there is nothing to search for.
 * "|" splits the search into alternatives, and the words on each side must all match:
 * "404 demo" finds 404s under /demo. "#req" matches the kind only, "@users" the route name
 * only, "$live" a section by its key or tab name; other words the name, route, status and kind.
 */
const searchMatcher = (query) => {
  const alternatives = String(query ?? '').toLowerCase().split('|')
    .map((side) => side.split(/\s+/).filter((word) => word.replace(/^[#$@]/, '')))
    .filter((words) => words.length);
  if (!alternatives.length) return null;

  const best = (needle, haystacks) => lowest(haystacks.map((haystack) => fuzzyScore(needle, String(haystack).toLowerCase())));
  const allOf = (entry, words) => {
    const text = `${entry.label} ${entry.route ?? ''} ${entry.status ?? ''} ${entry.kind}`;
    let total = 0;
    for (const word of words) {
      const fields = { '#': [entry.kind], $: entry.sections ?? [], '@': entry.route ? [entry.route] : [] }[word[0]] ?? [text];
      const score = best(word.replace(/^[#$@]/, ''), fields);
      if (score === null) return null;
      total += score;
    }
    return total;
  };

  return (entry) => lowest(alternatives.map((words) => allOf(entry, words)));
};

Alpine.data('monocle', (config) => (usePathMappings(config.paths), {
  api: config.api,
  /** The captures the server returned; `captures` is what the search field leaves of them. */
  loaded: [],
  total: 0,
  filters: { type: '', q: '', errors: false },
  hidden: hiddenNames.get(),
  live: true,
  selectedId: null,
  capture: null,
  model: null,
  tab: 'overview',
  openGroups: [],
  tabData: null,
  tabLoading: false,
  rowGroups: {},
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
  wide: true,

  init() {
    const wide = matchMedia('(min-width: 861px)');
    this.wide = wide.matches;
    wide.addEventListener('change', (e) => { this.wide = e.matches; });
    this.popover = setupPopover(this.$refs.detail, () => this.model);
    setupTooltip();
    setupDragScroll('[role="tablist"]');
    const fromHash = location.hash.replace(/^#/, '').split('/');
    if (fromHash[0]) { this.selectedId = fromHash[0]; this.tab = fromHash[1] || 'overview'; }
    this.load();
    // A link or an edited address opens that capture and tab; our own replaceState calls do not fire this.
    addEventListener('hashchange', () => {
      const [id, tab = 'overview'] = location.hash.replace(/^#/, '').split('/');
      if (!id) return;
      if (id !== this.selectedId) this.select(id, tab);
      else if (tab !== this.tab) this.openTab(tab);
    });
    setInterval(() => this.live && !document.hidden && this.load(true), 2500);
    this.$watch('filters', () => this.load(), { deep: true });
    this.$watch('palette.query', () => { this.palette.index = 0; this.palette.confirmClear = false; });
    addEventListener('keydown', (e) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        return this.palette.open ? this.closePalette() : this.openPalette();
      }
    });
    addEventListener('keydown', (e) => {
      if (e.metaKey || e.ctrlKey || e.altKey || e.target.closest?.('input, textarea, select, [contenteditable], [role="tab"]')) return;
      const tabStep = { ArrowRight: 1, i: 1, ArrowLeft: -1, u: -1 }[e.key];
      if (tabStep && this.capture) {
        e.preventDefault();
        return this.stepTab(tabStep, false);
      }
      if (e.key === 'Home' && this.captures[0]) {
        e.preventDefault();
        if (this.captures[0].id !== this.selectedId) this.select(this.captures[0].id, this.tab);
        return this.$nextTick(() => document.querySelector('[aria-current="true"]')?.scrollIntoView({ block: 'nearest' }));
      }
      const step = { j: 1, ArrowDown: 1, k: -1, ArrowUp: -1 }[e.key];
      if (!step || !this.captures.length) return;
      e.preventDefault();
      const index = this.captures.findIndex((c) => c.id === this.selectedId);
      const next = this.captures[Math.max(0, Math.min(this.captures.length - 1, index + step))];
      if (next && next.id !== this.selectedId) {
        this.select(next.id, this.tab);
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
    if (this.filters.errors) params.set('has_errors', '1');
    this.hidden.forEach((name) => params.append('exclude[]', name));
    try {
      const result = await this.fetchJson(`${this.api}?${params}`);
      this.loaded = result.rows;
      this.total = result.total;
      this.error = null;
      // A filtered list hides captures on purpose; only an unfiltered or empty one means it may be gone.
      const filtered = this.filters.type || this.filters.errors || this.hidden.length;
      if (this.selectedId && (!filtered || !this.loaded.length) && !this.loaded.some((c) => c.id === this.selectedId)) await this.dropIfGone();
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
    const row = this.loaded.find((c) => c.id === capture.id);
    return row?.duration ?? 0;
  },

  async openTab(tab) {
    this.popover.close();
    const release = this.holdAtTabs();
    this.tab = tab;
    this.tabData = null;
    history.replaceState(null, '', `#${this.selectedId}/${tab}`);
    this.$nextTick(() => this.$refs.tabs?.querySelector('[aria-selected="true"]')?.scrollIntoView({ inline: 'nearest', block: 'nearest', behavior: 'smooth' }));
    if (['timeline', 'overview'].includes(tab)) this.$nextTick(release);
    if (tab === 'timeline') return this.renderTimeline();
    if (tab === 'overview') return;
    this.tabLoading = true;
    try {
      const base = `${this.api}/${encodeURIComponent(this.selectedId)}`;
      this.openGroups = [];
      this.openRows = [];
      // Sections the tab includes (auth on the request tab) come along under tabData.included.
      const included = {};
      for (const key of this.presentationOf(tab)?.includes ?? []) {
        if (this.summary.counts?.[key]) included[key] = (await this.fetchJson(`${base}/rows/${encodeURIComponent(key)}?limit=500&verbose=1`)).rows ?? [];
      }
      const data = tab === 'trace'
          ? await this.fetchJson(`${base}/trace`)
          : await this.fetchJson(`${base}/rows/${encodeURIComponent(tab)}?limit=${this.presentationOf(tab)?.groupings?.length ? 500 : 100}&verbose=1`);
      this.tabData = { ...data, included };
    } catch (e) {
      this.error = e.message;
    } finally {
      this.tabLoading = false;
      this.$nextTick(release);
    }
  },

  /**
   * A new tab starts just below the tab bar: scrolled up to where the bar sits when not
   * stuck, never down. The capture keeps its height while the tab loads, so the scroll
   * holds; the returned function lets go of it once the tab is drawn.
   */
  holdAtTabs() {
    const pane = this.$refs.detail;
    const body = this.$refs.tabs?.parentElement;
    let above = this.$refs.tabs?.previousElementSibling;
    while (above && !above.getClientRects().length) above = above.previousElementSibling;
    if (!pane || !body || !above) return () => {};
    const gap = parseFloat(getComputedStyle(body).rowGap) || 0;
    const top = Math.min(pane.scrollTop, above.getBoundingClientRect().bottom - pane.getBoundingClientRect().top + pane.scrollTop + gap);
    body.style.minHeight = `${top + pane.clientHeight}px`;
    pane.scrollTo({ top });
    return () => { body.style.minHeight = ''; };
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
    const tabs = [{ key: 'overview', label: 'Overview' }, { key: 'timeline', label: 'Timeline' }, ...(this.capture.tabs ?? []).map((t) => ({ ...t, label: t.label ?? humanize(t.key) }))];
    if (this.summary.parent_id || this.summary.children?.length) tabs.push({ key: 'trace', label: 'Trace', count: this.summary.children?.length || undefined });
    return tabs;
  },


  /** The command palette: quick actions, a tab of the open capture, or another capture. */
  palette: { open: false, query: '', index: 0, confirmClear: false },

  /** The key that opens the palette, as this machine names it. */
  paletteShortcut: /Mac|iPhone|iPad/.test(navigator.platform) ? '⌘K' : 'Ctrl K',

  openPalette() {
    this.palette = { open: true, query: '', index: 0, confirmClear: false };
    this.$nextTick(() => document.getElementById('monocle-palette-input')?.focus());
  },

  closePalette() {
    this.palette.open = false;
    this.palette.confirmClear = false;
  },

  /**
   * Entries in groups, in this order: quick actions (the latest capture of each type, then
   * clearing), tabs, then captures by type. With a query (see searchMatcher), each group keeps
   * its matching entries, closest first.
   */
  get paletteItems() {
    const types = ['request', 'command', 'job', 'mcp'];
    const typeName = (type) => (type === 'mcp' ? 'MCP call' : type);
    const groupOf = { request: 'Requests', command: 'Commands', job: 'Jobs', mcp: 'MCP' };
    const capture = (c, group, label = c.name, hint = ago(c.started_at)) => ({ ...this.captureEntry(c), id: `${group}:${c.id}`, group, label, hint, run: () => this.select(c.id, this.tab) });

    const quick = types
      .map((type) => this.captures.find((c) => c.type === type))
      .filter(Boolean)
      .map((c) => capture(c, 'Quick actions', `Latest ${typeName(c.type)}`, c.name));
    if (this.captures.length) {
      quick.push({
        id: 'clear',
        group: 'Quick actions',
        kind: 'clear',
        label: this.palette.confirmClear ? 'Delete all captures? Press Enter again' : 'Clear captures',
        hint: '',
        danger: this.palette.confirmClear,
        // The first time only asks; the palette stays open for the answer.
        run: () => (this.palette.confirmClear ? (this.clearAll(), false) : (this.palette.confirmClear = true)),
      });
    }

    const tabs = this.tabs.map((t) => ({ id: `tab:${t.key}`, group: 'Tabs', kind: 'tab', label: t.label, hint: t.count ? String(t.count) : '', sections: [t.key, t.label], run: () => this.openTab(t.key) }));
    const byType = types.flatMap((type) => this.captures.filter((c) => c.type === type).map((c) => capture(c, groupOf[type])));

    const match = searchMatcher(this.palette.query);
    if (!match) {
      const shown = {};
      return [...quick, ...tabs, ...byType.filter((item) => (shown[item.group] = (shown[item.group] ?? 0) + 1) <= 5)];
    }

    const groups = ['Quick actions', 'Tabs', ...types.map((type) => groupOf[type])];
    return [...quick, ...tabs, ...byType]
      .map((item) => ({ item, score: match(item) }))
      .filter((match) => match.score !== null)
      .sort((a, b) => groups.indexOf(a.item.group) - groups.indexOf(b.item.group) || a.score - b.score)
      .map((match) => match.item)
      .filter((item, i, all) => all.slice(0, i).filter((x) => x.group === item.group).length < 10);
  },

  /** Arrow keys move through the list, Enter runs the chosen entry, Escape closes. */
  paletteKey(e) {
    const count = this.paletteItems.length;
    if (e.key === 'Escape') return this.closePalette();
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      this.palette.index = count ? (this.palette.index + (e.key === 'ArrowDown' ? 1 : -1) + count) % count : 0;
      return this.$nextTick(() => document.getElementById(`monocle-palette-${this.palette.index}`)?.scrollIntoView({ block: 'nearest' }));
    }
    if (e.key === 'Enter') {
      e.preventDefault();
      this.runPalette(this.paletteItems[this.palette.index]);
    }
  },

  runPalette(item) {
    if (!item) return;
    // An entry that returns true keeps the palette open (clearing asks first).
    if (item.run() === true) return;
    this.closePalette();
  },

  /** A capture as the search sees it: name, route, status, kind and its sections by key and tab name. */
  captureEntry(c) {
    return {
      label: c.name,
      route: c.route,
      status: c.status,
      kind: shortType(c.type),
      sections: Object.keys(c.counts ?? {}).flatMap((key) => [key, config.labels?.[key] ?? humanize(key)]),
    };
  },

  /** The loaded captures the search field matches, newest first, with the palette's syntax. */
  get captures() {
    const match = searchMatcher(this.filters.q);
    return match ? this.loaded.filter((c) => match(this.captureEntry(c)) !== null) : this.loaded;
  },

  get summary() { return this.capture?.summary ?? {}; },
  get phases() { return (this.summary.breakdown?.phases ?? []).filter((p) => p.name !== 'other' || p.ms >= 0.05); },
  /** "2 errors, 1 warning" for a tab, or empty. */
  problemTip(t) {
    const part = (n, word) => (n ? `${n} ${word}${n === 1 ? '' : 's'}` : null);
    return [part(t.errors, 'error'), part(t.problems, 'warning')].filter(Boolean).join(', ');
  },

  /** Errors first, then warnings, each with the links found in its text. */
  get problemList() {
    const { errors = [], warnings = [] } = this.summary;
    return [
      ...errors.map((text, i) => ({ text, links: this.capture.error_links?.[i], error: true })),
      ...warnings.map((text, i) => ({ text, links: this.capture.warning_links?.[i], error: false })),
    ];
  },
  get categories() { return this.summary.breakdown?.categories ?? []; },
  get maxCategory() { return Math.max(1, ...this.categories.map((c) => c.ms)); },

  /** Totals grouped by what they are about, each group in a fixed order. */
  /** A total as [name, value, unit, label], or null when the overview leaves it out (empty, zero or a list). */
  totalItem(name) {
    const value = this.summary.totals?.[name];
    if (value === null || value === undefined || value === 0 || typeof value === 'object') return null;
    return [name, value, this.summary.units?.[name], this.summary.labels?.[name]];
  },

  /** Totals about the whole capture, shown at the top of the overview. */
  get headlineTotals() {
    return (this.summary.headlines ?? []).map((name) => this.totalItem(name)).filter(Boolean);
  },

  /** The other totals under the lens that reported them, in tab order. */
  get totalGroups() {
    const groups = new Map();
    for (const [name, key] of Object.entries(this.summary.groups ?? {})) {
      const item = this.totalItem(name);
      if (!item) continue;
      if (!groups.has(key)) {
        const tab = this.tabs.find((t) => t.key === key);
        groups.set(key, { key, label: tab?.label ?? this.capture.presentations?.[key]?.label ?? humanize(key), tab: tab?.key, items: [] });
      }
      groups.get(key).items.push(item);
    }
    const order = this.tabs.map((t) => t.key);
    const rank = (key) => (order.includes(key) ? order.indexOf(key) : order.length);
    return [...groups.values()].sort((a, b) => rank(a.key) - rank(b.key));
  },

  /** The open tab's own totals, shown above its rows. */
  get tabTotals() {
    return this.totalGroups.find((g) => g.key === this.tab)?.items ?? [];
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
    const line = (section, items) => `- ${section}: ${items.map((t) => `${this.totalLabel(t)} ${this.totalValue(t)}`).join(', ')}`;
    const totals = [line('overall', this.headlineTotals), ...this.totalGroups.map((g) => line(g.key, g.items))];
    if (totals.length) lines.push('', 'Totals per section:', ...totals);
    if (s.flags?.length) lines.push('', 'Monocle flagged:', ...s.flags.map((f) => `- ${f.level} in ${f.section}: ${f.message}`));
    lines.push('', 'Explain what is slow or wrong, point at the file:line to change (every row has an origin), make the change, reproduce the request and compare the new capture with monocle-compare.');
    return lines.join('\n');
  },

  /** Monocle's own time recording the open tab's section, when it took any. */
  get tabOverhead() {
    const ms = this.summary.monocle_by_section?.[this.tab];
    return typeof ms === 'number' && ms > 0 ? ms : null;
  },

  /** What a total is made of (name => value in the total's unit), when the summary splits it up. */
  totalDetails(t) {
    const details = this.summary.details?.[t[0]];
    return details && Object.keys(details).length ? details : null;
  },

  /** Open the parts of a total next to it, largest first. */
  totalPopover(event, t) {
    const details = this.totalDetails(t);
    if (!details) return;
    event.stopPropagation();
    const rows = Object.entries(details).sort((a, b) => b[1] - a[1])
      .map(([name, value]) => `<tr><td class="py-0.5 pr-4 font-mono text-xs">${esc(name)}</td><td class="py-0.5 text-right tabular-nums">${esc(this.totalValue([name, value, t[2]]))}</td></tr>`)
      .join('');
    this.popover.openDetails(event.currentTarget, `
      <div class="flex items-center gap-2"><span class="font-semibold first-letter:uppercase">${esc(this.totalLabel(t))}</span><span class="tabular-nums text-muted">${esc(this.totalValue(t))}</span><button class="ml-auto px-0.5 text-lg leading-none text-muted" data-pop-close aria-label="Close">×</button></div>
      <table class="w-full border-collapse text-[13px]"><tbody>${rows}</tbody></table>`, event.clientX);
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
  stepTab(direction, focus = true) {
    const keys = this.tabs.map((t) => t.key);
    const next = keys[(keys.indexOf(this.tab) + direction + keys.length) % keys.length];
    this.openTab(next);
    if (focus) this.$nextTick(() => document.getElementById('monocle-tab-' + next)?.focus());
  },

  linkHtml(text, link) { return text ? maybeLink(text, link) : ''; },



  /** The tables in a generic tab: the tab's own rows, and model changes under the model counts. */



  get tables() {
    if (this.groups.length) return [];
    const rows = this.tabData?.rows ?? [];
    const own = { key: this.tab, title: null, rows };
    const included = Object.entries(this.tabData?.included ?? {}).map(([key, rows]) => ({ key, title: this.presentationOf(key)?.label ?? humanize(key), rows }));
    return [own, ...included];
  },

  get tableColumns() {
    return this.columnsOf(this.tabData?.rows ?? [], this.tab);
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
    const formats = Object.entries(this.presentationOf(this.tab)?.formats ?? {});
    // A row failed when it says so, or when a field its lens shows as a bad flag or an HTTP status says so.
    const failed = (row) => row.failed === true || formats.some(([field, [format, , , tone = 'bad']]) => (format === 'flag' && tone === 'bad' && row[field] === false) || (format === 'http-status' && (row[field] == null || row[field] >= 400)));
    const groups = new Map();
    (this.tabData?.rows ?? []).forEach((row, i) => {
      const key = keyOf(row);
      // The group shows its value the way the table cell would (a coloured status, #1 for a pool).
      const g = groups.get(key) ?? { key, html: this.cell(row, by, this.presentationOf(this.tab)) || '<span class="text-muted">(none)</span>', count: 0, failed: 0, total_ms: 0, max_ms: 0, origin: row.origin, rows: [] };
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

  /** Whether the open section's rows have durations, so its groups show timing. */
  get groupsTimed() {
    return (this.tabData?.rows ?? []).some((row) => typeof row.duration === 'number');
  },

  /** A row inside a group, in one line: the columns that tell it apart from the rest of its group. */
  memberLabel(row) {
    const skip = [this.rowGroup, 'duration', 'time', 'origin', this.memberBadgeField];
    const presentation = this.presentationOf(this.tab);
    const columns = this.columnsOf(this.tabData?.rows ?? [], this.tab).filter((c) => !skip.includes(c));
    const rows = this.tabData?.rows ?? [];
    // Numbers say little without their column, so they carry its name.
    const labelled = (c, html) => (this.numeric(c, rows) ? `<span class="text-muted">${esc(this.columnLabel(c, this.tab).toLowerCase())}</span> ${html}` : html);
    return columns.map((c) => [c, this.cell(row, c, presentation)]).filter(([, html]) => html).map(([c, html]) => labelled(c, html)).join(' <span class="text-muted">·</span> ');
  },

  /** The field a row inside a group leads with: the first status or level its lens formats. */
  get memberBadgeField() {
    const formats = this.presentationOf(this.tab)?.formats ?? {};
    return Object.keys(formats).find((field) => ['http-status', 'status', 'level'].includes(formats[field][0])) ?? null;
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

  cls, ms, ago, shortType, statusClass, tagClass, humanize, esc, maybeLink, rowBody, classes,
  phaseColor(name) { return PHASE_COLORS[name] ?? 'var(--c-phase)'; },
  /** A table cell; formats that link to other captures look among this capture's children. */
  cell(row, key, presentation = null) { return cells.cell(row, key, presentation, { children: this.capture?.children ?? [] }); },

  categoryColor(name) { return CATEGORY_COLORS[name] ?? LENS_COLOR[name] ?? 'var(--c-phase)'; },

  originHtml(origin) { return origin?.file ? nameLink(origin.file + (origin.line ? ':' + origin.line : ''), origin.link, cls.origin) : ''; },
}));

Alpine.plugin(collapse);
window.Alpine = Alpine;
Alpine.start();
