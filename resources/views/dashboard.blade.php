<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Monocle - {{ config('app.name') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ $assets['icon'] }}">
    <link rel="stylesheet" href="{{ $assets['css'] }}">
    <script defer src="{{ $assets['js'] }}"></script>
</head>
<body>
<div class="app" x-data="monocle(@js(['api' => $api, 'csrf' => csrf_token()]))" x-cloak>
    <aside class="list" aria-label="Captures">
        <div class="brand">
            <img class="logo" src="{{ $assets['icon'] }}" width="26" height="26" alt="">
            <h1>Monocle</h1>
            <span class="app-name">{{ config('app.name') }} · {{ app()->environment() }}</span>
            <div class="theme" role="group" aria-label="Theme">
                <template x-for="option in ['system', 'light', 'dark']" :key="option">
                    <button type="button" @click="setTheme(option)" :aria-pressed="theme === option" x-text="option === 'system' ? 'Auto' : humanize(option)"></button>
                </template>
            </div>
        </div>
        <div class="filters">
            <input class="search" id="monocle-search" type="search" placeholder="Filter by path or name" aria-label="Filter captures" x-model.debounce.300ms="filters.q">
            <div class="types" role="group" aria-label="Capture type">
                <template x-for="type in [['', 'All'], ['request', 'Requests'], ['job', 'Jobs'], ['command', 'Commands'], ['mcp', 'MCP']]" :key="type[0]">
                    <button type="button" class="chip" @click="filters.type = type[0]" :aria-pressed="filters.type === type[0]" x-text="type[1]"></button>
                </template>
            </div>
            <div class="filter-row">
                <label class="toggle"><input type="checkbox" id="monocle-errors" x-model="filters.errors"> Only errors</label>
                <label class="toggle"><input type="checkbox" id="monocle-live" x-model="live"> Live</label>
                <button type="button" class="link clear" x-show="!confirmClear" @click="confirmClear = true">Clear</button>
                <span class="confirm" x-show="confirmClear">
                    Delete all captures?
                    <button type="button" class="link danger" @click="clearAll()">Delete</button>
                    <button type="button" class="link" @click="confirmClear = false">Cancel</button>
                </span>
            </div>
        </div>
        <div class="rows">
            <template x-for="c in captures" :key="c.id">
                <button type="button" class="row" @click="select(c.id)" :aria-current="c.id === selectedId">
                    <span class="type" x-text="c.type"></span>
                    <span class="name" x-text="c.name"></span>
                    <span class="dur num" x-text="ms(c.duration)"></span>
                    <span class="sub">
                        <span class="status" :class="statusClass(c.status)" x-text="c.status ?? ''"></span>
                        <span x-show="c.counts?.queries" x-text="(c.counts?.queries ?? 0) + ' queries'"></span>
                        <span x-show="c.has_errors"><i class="dot"></i> error</span>
                        <span x-show="c.label" class="label-pill" x-text="c.label"></span>
                        <span x-text="ago(c.started_at)"></span>
                    </span>
                </button>
            </template>
            <p class="empty" style="margin:16px" x-show="!captures.length && !error">No captures yet. Open a page, run a job or a command, and it shows up here.</p>
            <p class="empty" style="margin:16px" x-show="error" x-text="error"></p>
        </div>
    </aside>

    <main class="detail" x-ref="detail" aria-live="polite">
        <p class="empty" x-show="!capture">Select a capture.</p>
        <template x-if="capture">
            <div class="detail-inner">
                <header class="head">
                    <h2 x-text="summary.name"></h2>
                    <span class="status" :class="statusClass(summary.status)" x-text="summary.status ?? ''"></span>
                    <span class="label-pill" x-show="summary.label" x-text="'label: ' + summary.label"></span>
                </header>
                <div class="facts">
                    <span><b class="num" x-text="ms(summary.totals?.duration_ms ?? model?.duration)"></b> total</span>
                    <span x-text="summary.type"></span>
                    <span class="mono" x-text="capture.id"></span>
                    <span x-text="summary.started_at ? ago(summary.started_at) : ''"></span>
                </div>
                <div class="trace-links" x-show="summary.parent_id || summary.children?.length">
                    <span x-show="summary.parent_id">Dispatched by <button type="button" class="link" @click="select(summary.parent_id)">parent capture</button></span>
                    <span x-show="summary.children?.length">Dispatched <button type="button" class="link" @click="openTab('trace')" x-text="summary.children?.length + ' job(s)'"></button></span>
                </div>

                <nav class="tabs" role="tablist">
                    <template x-for="t in tabs" :key="t.key">
                        <button type="button" class="tab" role="tab" :title="t.description ?? ''" @click="openTab(t.key)" :aria-selected="tab === t.key">
                            <span x-text="t.label"></span><span class="count" x-show="t.count" x-text="t.count"></span>
                        </button>
                    </template>
                </nav>

                <section x-show="tab === 'overview'" class="grid2">
                    <div class="col">
                        <div x-show="phases.length">
                            <h3 class="section-title">Where the time went</h3>
                            <div class="stack" role="img" aria-label="Time per phase">
                                <template x-for="p in phases" :key="p.name">
                                    <span :title="p.name + ': ' + ms(p.ms)" :style="`width:${p.percent}%;background:${phaseColor(p.name)}`"></span>
                                </template>
                            </div>
                            <div class="legend">
                                <template x-for="p in phases" :key="p.name">
                                    <div>
                                        <i :style="`background:${phaseColor(p.name)}`"></i>
                                        <span><span x-text="p.name"></span> <span class="note" x-show="p.count > 1" x-text="'×' + p.count"></span></span>
                                        <span class="ms num" x-text="ms(p.ms)"></span>
                                        <span class="pct" x-text="p.percent + ' %'"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                        <div x-show="categories.length">
                            <h3 class="section-title">Spent on</h3>
                            <div class="cats">
                                <template x-for="c in categories" :key="c.name">
                                    <div class="cat">
                                        <span x-text="c.name"></span>
                                        <div class="bar"><span :style="`width:${(c.ms / maxCategory) * 100}%;background:${categoryColor(c.name)}`"></span></div>
                                        <span class="val num" x-text="ms(c.ms) + ' · ' + c.count + ' ops'"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <div>
                            <h3 class="section-title">Warnings</h3>
                            <div class="warnings" x-show="summary.warnings?.length">
                                <template x-for="w in summary.warnings" :key="w">
                                    <div class="warning"><span class="sev">!</span><span class="msg" x-text="w"></span></div>
                                </template>
                            </div>
                            <p class="empty" x-show="!summary.warnings?.length">No warnings.</p>
                        </div>
                        <div>
                            <h3 class="section-title">Totals</h3>
                            <div class="totals">
                                <template x-for="t in totals" :key="t[0]">
                                    <div class="total"><div class="k" x-text="totalLabel(t[0])"></div><div class="v" x-text="totalValue(t)"></div></div>
                                </template>
                            </div>
                        </div>
                    </div>
                </section>

                <section x-show="tab === 'timeline'" @click="timelineClick($event)" x-html="timelineHtml"></section>

                <section x-show="tab === 'queries'">
                    <div class="group-by">Group by
                        <template x-for="g in ['shape', 'caller', 'table', 'transaction']" :key="g">
                            <button type="button" class="chip" @click="groupBy = g; openTab('queries')" :aria-pressed="groupBy === g" x-text="g"></button>
                        </template>
                    </div>
                    <div class="table-wrap" x-show="tabData?.rows?.length">
                        <table>
                            <thead><tr><th>Query</th><th class="r">Count</th><th class="r">Total</th><th class="r">Avg</th><th class="r">Max</th></tr></thead>
                            <tbody>
                                <template x-for="q in tabData?.rows ?? []" :key="q.key">
                                    <tr>
                                        <td><code class="sql" x-text="q.example ?? q.key"></code><span x-html="originHtml(q.origin)"></span></td>
                                        <td class="r num" x-text="q.count"></td>
                                        <td class="r num" x-text="ms(q.total_ms)"></td>
                                        <td class="r num" x-text="ms(q.avg_ms)"></td>
                                        <td class="r num" x-text="ms(q.max_ms)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section x-show="tab === 'trace'">
                    <div class="trace">
                        <template x-for="x in tabData?.captures ?? []" :key="x.id">
                            <div class="tnode" :class="{ current: x.id === selectedId }" :style="`margin-left:${(x.depth ?? 0) * 24}px`">
                                <span class="t-name">
                                    <span class="type" x-text="x.type"></span>
                                    <button type="button" class="link" x-show="x.id !== selectedId" @click="select(x.id)" x-text="x.name"></button>
                                    <span x-show="x.id === selectedId" x-text="x.name"></span>
                                </span>
                                <span class="status" :class="statusClass(x.status)" x-text="x.status ?? ''"></span>
                                <span class="off num" x-text="ms(x.duration)"></span>
                            </div>
                        </template>
                    </div>
                </section>

                <section x-show="!['overview', 'timeline', 'queries', 'trace'].includes(tab)">
                    <div class="table-wrap" x-show="tabData?.rows?.length">
                        <table>
                            <thead><tr><template x-for="col in tableColumns" :key="col"><th x-text="col"></th></template></tr></thead>
                            <tbody>
                                <template x-for="(row, i) in tabData?.rows ?? []" :key="i">
                                    <tr><template x-for="col in tableColumns" :key="col"><td x-html="cell(row, col)"></td></template></tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <dl class="kv" x-show="tabData?.data">
                        <template x-for="[k, v] in Object.entries(tabData?.data ?? {})" :key="k">
                            <div><dt x-text="k"></dt><dd class="mono" x-text="typeof v === 'object' ? JSON.stringify(v) : v"></dd></div>
                        </template>
                    </dl>
                    <button type="button" class="link more" x-show="tabData?.next_offset != null" @click="loadMore()" x-text="'Show more (' + (tabData?.total - tabData?.rows?.length) + ' left)'"></button>
                </section>
                <p class="note" x-show="tabLoading">Loading...</p>
            </div>
        </template>
    </main>
</div>
</body>
</html>
