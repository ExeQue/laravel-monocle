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
<body class="min-[861px]:h-dvh min-[861px]:overflow-hidden">
<div class="flex min-h-dvh flex-col min-[861px]:grid min-[861px]:h-dvh min-[861px]:grid-cols-[minmax(280px,340px)_minmax(0,1fr)]" x-data="monocle(@js(['api' => $api, 'csrf' => csrf_token()]))" x-cloak>
    <aside class="flex min-h-0 min-w-0 shrink-0 flex-col border-b border-line bg-panel max-[860px]:max-h-[75dvh] min-[861px]:overflow-hidden min-[861px]:border-r min-[861px]:border-b-0" aria-label="Captures">
        <div class="flex flex-wrap items-center gap-x-2.5 gap-y-2 border-b border-line px-4 pt-[18px] pb-3.5">
            <img class="block shrink-0" src="{{ $assets['icon'] }}" width="26" height="26" alt="">
            <h1 class="m-0 text-lg font-bold tracking-[.01em]">Monocle</h1>
            <span class="ml-auto text-xs text-muted">{{ config('app.name') }} · {{ app()->environment() }}</span>
            <div class="flex gap-0.5 rounded-full border border-line p-0.5" role="group" aria-label="Theme">
                <template x-for="option in ['system', 'light', 'dark']" :key="option">
                    <button type="button" class="rounded-full px-2 py-0.5 text-[11px] text-muted aria-pressed:bg-brass aria-pressed:text-on-brass" @click="setTheme(option)" :aria-pressed="theme === option" x-text="option === 'system' ? 'Auto' : humanize(option)"></button>
                </template>
            </div>
        </div>
        <button type="button" class="flex items-center justify-between gap-2 border-b border-line px-4 py-2.5 text-left text-[13px] min-[861px]:hidden" x-show="capture" @click="listOpen = !listOpen" :aria-expanded="listOpen">
            <span><span class="font-medium">Captures</span> <span class="text-muted" x-text="'(' + total + ')'"></span></span>
            <svg class="size-4 text-brass transition-transform duration-200" :class="listOpen && 'rotate-180'" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3.5 6 8 10.5 12.5 6"/></svg>
        </button>
        <div class="flex min-h-0 flex-1 flex-col" x-show="wide || !capture || listOpen" x-collapse>
        <div class="flex flex-col gap-2.5 border-b border-line px-4 py-3">
            <input class="w-full rounded-md border border-line bg-bg px-2.5 py-2 text-fg" id="monocle-search" type="search" placeholder="Filter by path or name" aria-label="Filter captures" x-model.debounce.300ms="filters.q">
            <div class="flex flex-wrap gap-1.5" role="group" aria-label="Capture type">
                <template x-for="type in [['', 'All'], ['request', 'Requests'], ['job', 'Jobs'], ['command', 'Commands'], ['mcp', 'MCP']]" :key="type[0]">
                    <button type="button" :class="cls.chip" @click="filters.type = type[0]" :aria-pressed="filters.type === type[0]" x-text="type[1]"></button>
                </template>
            </div>
            <div class="flex flex-wrap items-center gap-1.5 text-xs text-muted" x-show="hidden.length">
                <span>Hidden</span>
                <template x-for="name in hidden" :key="name">
                    <span class="inline-flex items-center gap-1 rounded-full border border-line py-0.5 pr-1 pl-2.5 font-mono text-[11px]">
                        <span x-text="name"></span>
                        <button type="button" class="rounded-full px-1 text-muted hover:text-fg" @click="unhide(name)" :aria-label="'Show ' + name + ' again'">×</button>
                    </span>
                </template>
            </div>
            <div class="flex flex-wrap items-center gap-x-3.5 gap-y-2 text-xs text-muted">
                <label class="flex items-center gap-1.5"><input type="checkbox" id="monocle-errors" class="accent-brass" x-model="filters.errors"> Only errors</label>
                <label class="flex items-center gap-1.5"><input type="checkbox" id="monocle-live" class="accent-brass" x-model="live"> Live</label>
                <button type="button" class="ml-auto" :class="cls.link" x-show="!confirmClear" @click="confirmClear = true">Clear</button>
                <span class="ml-auto flex items-center gap-2" x-show="confirmClear">
                    Delete all captures?
                    <button type="button" class="text-bad underline underline-offset-[3px]" @click="clearAll()">Delete</button>
                    <button type="button" :class="cls.link" @click="confirmClear = false">Cancel</button>
                </span>
            </div>
        </div>
        <div class="scroll-quiet min-h-0 flex-1 overflow-y-auto overscroll-contain">
            <template x-for="c in captures" :key="c.id">
                <div class="group relative">
                <button type="button" class="grid w-full grid-cols-[auto_minmax(0,1fr)_auto] items-baseline gap-x-2.5 gap-y-1 border-b border-line px-4 py-2.5 text-left hover:bg-sunk aria-[current=true]:bg-brass-soft aria-[current=true]:shadow-[inset_3px_0_0_var(--brass)]" @click="select(c.id)" :aria-current="c.id === selectedId">
                    <span :class="cls.type" x-text="c.type"></span>
                    <span class="overflow-hidden text-ellipsis whitespace-nowrap font-medium" x-text="c.name"></span>
                    <span class="font-mono text-xs tabular-nums" x-text="ms(c.duration)"></span>
                    <span class="col-span-2 col-start-2 flex flex-wrap gap-2.5 text-xs text-muted">
                        <span class="font-mono" :class="statusClass(c.status)" x-text="c.status ?? ''"></span>
                        <span x-show="c.counts?.queries" x-text="(c.counts?.queries ?? 0) + ' queries'"></span>
                        <span x-show="c.has_errors" class="text-bad"><i class="inline-block size-[7px] rounded-full bg-bad"></i> error</span>
                        <span x-show="c.label" class="rounded-full border border-dashed border-brass px-2 text-brass" x-text="c.label"></span>
                        <span x-text="ago(c.started_at)"></span>
                    </span>
                </button>
                <button type="button" class="absolute right-3 bottom-2 rounded px-1.5 text-[11px] text-muted opacity-0 group-hover:opacity-100 hover:text-fg focus-visible:opacity-100 max-[860px]:opacity-100" @click.stop="hide(c.route ?? c.name)" :title="'Hide ' + (c.route ?? c.name)" x-text="'hide ' + ({ request: 'route', command: 'command', job: 'job', mcp: 'call' }[c.type] ?? 'these')"></button>
                </div>
            </template>
            <p class="m-4" :class="cls.empty" x-show="!captures.length && !error">No captures yet. Open a page, run a job or a command, and it shows up here.</p>
            <p class="m-4" :class="cls.empty" x-show="error" x-text="error"></p>
        </div>
        </div>
    </aside>

    <main class="scroll-quiet flex min-h-0 min-w-0 flex-1 flex-col gap-5 px-4 pt-5 pb-10 min-[861px]:overflow-y-auto min-[861px]:overscroll-contain min-[861px]:px-6" x-ref="detail" aria-live="polite">
        <p :class="cls.empty" x-show="!capture">Select a capture.</p>
        <template x-if="capture">
            <div class="flex min-w-0 flex-col gap-5">
                <header class="flex flex-wrap items-center gap-x-3 gap-y-2.5">
                    <h2 class="m-0 min-w-0 text-2xl font-bold text-balance [overflow-wrap:anywhere]" x-text="summary.name"></h2>
                    <span class="font-mono text-xs" :class="statusClass(summary.status)" x-text="summary.type === 'command' && summary.status !== null ? 'exit ' + summary.status : (summary.status ?? '')"></span>
                    <span class="rounded-full border border-line px-2 font-mono text-xs text-muted" x-show="capture.context?.route" x-text="capture.context?.route" title="Route name"></span>
                    <span class="rounded-full border border-dashed border-brass px-2 text-xs text-brass" x-show="summary.label" x-text="'label: ' + summary.label"></span>
                </header>
                <div class="flex flex-wrap items-center gap-x-[18px] gap-y-1.5 text-[13px] text-muted">
                    <span><b class="font-semibold text-fg tabular-nums" x-text="ms(summary.totals?.duration_ms ?? model?.duration)"></b> total</span>
                    <span x-text="summary.type"></span>
                    <span class="font-mono text-xs [overflow-wrap:anywhere]" x-show="capture.context?.action && capture.context.action !== 'Closure'" x-html="linkHtml(capture.context?.action, capture.context?.action_link)"></span>
                    <span class="font-mono text-xs [overflow-wrap:anywhere]" x-show="capture.context?.command_class" x-html="linkHtml(capture.context?.command_class, capture.context?.command_link)"></span>
                    <span class="inline-flex items-center gap-1">
                        <span class="font-mono text-xs select-all" x-text="capture.id"></span>
                        <button type="button" class="rounded p-0.5 text-muted hover:text-brass" @click="copy(capture.id, 'id')" :title="copied === 'id' ? 'Copied' : 'Copy id'" :aria-label="copied === 'id' ? 'Copied' : 'Copy capture id'">
                            <svg x-show="copied !== 'id'" class="size-3.5" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5.5" y="5.5" width="8" height="8" rx="1.5"/><path d="M10.5 5.5V3.5A1.5 1.5 0 0 0 9 2H3.5A1.5 1.5 0 0 0 2 3.5V9a1.5 1.5 0 0 0 1.5 1.5h2"/></svg>
                            <svg x-show="copied === 'id'" x-cloak class="size-3.5 text-ok" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 8.5 6.5 12 13 4.5"/></svg>
                        </button>
                    </span>
                    <span x-text="summary.started_at ? ago(summary.started_at) : ''"></span>
                    <button type="button" class="ml-auto inline-flex items-center gap-1.5 rounded-full border border-line px-2.5 py-0.5 text-xs text-muted hover:border-brass hover:text-brass" @click="copy(agentPrompt, 'prompt')" title="Copy a prompt that asks your coding agent to look into this capture through the Monocle MCP server">
                        <svg class="size-3.5" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.5 3.5h11v7h-6l-3 2.5v-2.5h-2z"/></svg>
                        <span x-text="copied === 'prompt' ? 'Copied' : 'Copy agent prompt'"></span>
                    </button>
                </div>
                <div class="flex flex-wrap gap-2 text-[13px]" x-show="summary.parent_id || summary.children?.length">
                    <span x-show="summary.parent_id">Dispatched by <button type="button" :class="cls.link" @click="select(summary.parent_id)">parent capture</button></span>
                    <span x-show="summary.children?.length">Dispatched <button type="button" :class="cls.link" @click="openTab('trace')" x-text="summary.children?.length + ' job(s)'"></button></span>
                </div>

                <nav class="scroll-quiet flex snap-x snap-mandatory scroll-px-2 gap-0.5 overflow-x-auto border-b border-line" role="tablist" x-ref="tabs">
                    <template x-for="t in tabs" :key="t.key">
                        <button type="button" class="flex shrink-0 snap-start items-baseline gap-1.5 whitespace-nowrap border-b-2 border-transparent px-3 py-2 text-muted aria-selected:border-brass aria-selected:font-semibold aria-selected:text-fg" role="tab" :title="t.description ?? ''" @click="openTab(t.key)" :aria-selected="tab === t.key">
                            <span x-text="t.label"></span><span class="font-mono text-[11px] text-muted" x-show="t.count" x-text="t.count"></span>
                        </button>
                    </template>
                </nav>

                <section x-show="tab === 'overview'" class="grid grid-cols-1 gap-6 min-[1101px]:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
                    <div class="grid min-w-0 content-start gap-6">
                        <div x-show="phases.length">
                            <h3 :class="cls.sectionTitle">Where the time went</h3>
                            <div class="flex h-[26px] overflow-hidden rounded bg-sunk" role="img" aria-label="Time per phase">
                                <template x-for="p in phases" :key="p.name">
                                    <span class="h-full min-w-0.5" :title="p.name + ': ' + ms(p.ms)" :style="`width:${p.percent}%;background:${phaseColor(p.name)}`"></span>
                                </template>
                            </div>
                            <div class="mt-3 grid grid-cols-[repeat(auto-fill,minmax(150px,1fr))] gap-x-4 gap-y-2">
                                <template x-for="p in phases" :key="p.name">
                                    <div class="grid grid-cols-[10px_minmax(0,1fr)_auto] items-baseline gap-x-2 text-[13px]">
                                        <i class="size-2.5 self-center rounded-sm" :style="`background:${phaseColor(p.name)}`"></i>
                                        <span><span x-text="p.name"></span> <span class="text-xs text-muted" x-show="p.count > 1" x-text="'×' + p.count"></span></span>
                                        <span class="font-mono text-xs tabular-nums" x-text="ms(p.ms)"></span>
                                        <span class="col-span-2 col-start-2 -mt-1 text-xs text-muted" x-text="p.percent + ' %'"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                        <div x-show="categories.length">
                            <h3 :class="cls.sectionTitle">Spent on</h3>
                            <div class="grid gap-2">
                                <template x-for="c in categories" :key="c.name">
                                    <div class="grid grid-cols-[70px_minmax(0,1fr)] items-center gap-3 text-[13px] sm:grid-cols-[90px_minmax(0,1fr)_140px]">
                                        <span x-text="c.name"></span>
                                        <div class="h-2 overflow-hidden rounded bg-sunk"><span class="block h-full rounded" :style="`width:${(c.ms / maxCategory) * 100}%;background:${categoryColor(c.name)}`"></span></div>
                                        <span class="col-start-2 font-mono text-xs text-muted tabular-nums sm:col-start-auto sm:text-right" x-text="ms(c.ms) + ' · ' + c.count + ' ops'"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                    <div class="grid min-w-0 content-start gap-6">
                        <div>
                            <h3 :class="cls.sectionTitle">Warnings</h3>
                            <div class="grid gap-2" x-show="summary.warnings?.length">
                                <template x-for="w in summary.warnings" :key="w">
                                    <div class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-2.5 rounded-md bg-warn-soft px-3 py-2.5">
                                        <span class="pt-0.5 font-mono text-[11px] text-warn">!</span>
                                        <span class="[overflow-wrap:anywhere]" x-text="w"></span>
                                    </div>
                                </template>
                            </div>
                            <p :class="cls.empty" x-show="!summary.warnings?.length">No warnings.</p>
                        </div>
                        <div>
                            <h3 :class="cls.sectionTitle">Totals</h3>
                            <div class="grid gap-4">
                                <template x-for="g in totalGroups" :key="g.label">
                                    <div class="grid gap-2 border-t border-line pt-3 first:border-t-0 first:pt-0">
                                        <h4 class="m-0 text-xs font-semibold text-brass" x-text="g.label"></h4>
                                        <div class="grid grid-cols-[repeat(auto-fill,minmax(120px,1fr))] gap-x-4 gap-y-3">
                                            <template x-for="t in g.items" :key="t[0]">
                                                <div><div class="text-xs text-muted" x-text="totalLabel(t[0], g.label)"></div><div class="text-xl font-bold tabular-nums" x-text="totalValue(t)"></div></div>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </section>

                <section x-show="tab === 'timeline'" @click="timelineClick($event)" x-html="timelineHtml"></section>

                <section x-show="tab === 'request'" class="grid gap-6">
                    <template x-if="request">
                        <div class="grid gap-6">
                            <div class="grid gap-2">
                                <h3 :class="cls.sectionTitle" class="mb-0">Request</h3>
                                <p class="m-0 font-mono text-[13px] [overflow-wrap:anywhere]"><b x-text="request.method"></b> <span x-text="request.url"></span></p>
                                <dl class="m-0 grid gap-x-4 gap-y-1 text-[13px] sm:grid-cols-[160px_minmax(0,1fr)]">
                                    <template x-for="[k, v, path] in [['Route', request.route?.name ?? request.route?.uri, 'route.name'], ['Action', request.route?.action, 'route.action'], ['User', request.auth ? (request.auth.user ?? request.auth.id ?? JSON.stringify(request.auth)) : null, 'auth.user'], ['IP', request.ip, 'ip'], ['User agent', request.user_agent, 'user_agent'], ['Locale', request.locale, 'locale']].filter(([, v]) => v)" :key="k">
                                        <div class="contents"><dt class="text-muted" x-text="k"></dt><dd class="m-0 font-mono text-xs [overflow-wrap:anywhere]" x-html="linked(v, path)"></dd></div>
                                    </template>
                                </dl>
                            </div>

                            <div>
                                <h3 :class="cls.sectionTitle">Payload</h3>
                                <div :class="cls.tableWrap" x-show="dot(request.query).length || dot(request.input).length">
                                    <table class="w-full border-collapse text-[13px]">
                                        <thead><tr><th :class="cls.th">Key</th><th :class="cls.th">Value</th><th :class="cls.th" class="max-sm:hidden">From</th></tr></thead>
                                        <tbody>
                                            <template x-for="[k, v, from] in [...dot(request.query).map((e) => [...e, 'query']), ...dot(request.input).map((e) => [...e, 'body'])]" :key="from + k">
                                                <tr><td :class="cls.td" class="min-w-32 font-mono text-xs break-words"><span x-text="k"></span><span class="block text-[10px] text-muted sm:hidden" x-text="from"></span></td><td :class="cls.td" class="font-mono text-xs" x-html="payloadValue(v)"></td><td :class="cls.td" class="text-xs text-muted max-sm:hidden" x-text="from"></td></tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                                <p :class="cls.empty" x-show="!dot(request.query).length && !dot(request.input).length && !request.files?.length">No query string or body.</p>
                                <div class="mt-3" :class="cls.tableWrap" x-show="request.files?.length">
                                    <table class="w-full border-collapse text-[13px]">
                                        <thead><tr><th :class="cls.th">File field</th><th :class="cls.th">Name</th><th :class="cls.th">Type</th><th :class="cls.th" class="text-right">Size</th></tr></thead>
                                        <tbody>
                                            <template x-for="f in request.files ?? []" :key="f.key + f.name">
                                                <tr><td :class="cls.td" class="font-mono text-xs" x-text="f.key"></td><td :class="cls.td" x-text="f.name"></td><td :class="cls.td" class="text-xs text-muted" x-text="f.mime"></td><td :class="cls.td" class="text-right tabular-nums" x-text="f.size"></td></tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div>
                                <h3 :class="cls.sectionTitle">Request headers</h3>
                                <div :class="cls.tableWrap">
                                    <table class="w-full border-collapse text-[13px]"><tbody>
                                        <template x-for="[k, v] in headers(request.headers)" :key="k">
                                            <tr><td :class="cls.td" class="w-56 font-mono text-xs text-muted" x-text="k"></td><td :class="cls.td" class="font-mono text-xs" x-text="v"></td></tr>
                                        </template>
                                    </tbody></table>
                                </div>
                            </div>

                            <div x-show="dot(request.cookies).length">
                                <h3 :class="cls.sectionTitle">Cookies</h3>
                                <div :class="cls.tableWrap">
                                    <table class="w-full border-collapse text-[13px]"><tbody>
                                        <template x-for="[k, v] in dot(request.cookies)" :key="k">
                                            <tr><td :class="cls.td" class="w-56 font-mono text-xs text-muted" x-text="k"></td><td :class="cls.td" class="font-mono text-xs" x-text="v"></td></tr>
                                        </template>
                                    </tbody></table>
                                </div>
                            </div>

                            <div class="grid gap-3">
                                <h3 :class="cls.sectionTitle" class="mb-0">Response</h3>
                                <p class="m-0 flex flex-wrap gap-x-4 gap-y-1 text-[13px]">
                                    <span class="font-mono" :class="statusClass(request.status)" x-text="request.status"></span>
                                    <span class="text-muted" x-text="request.content_type"></span>
                                    <span class="text-muted tabular-nums" x-show="request.response_bytes != null" x-text="totalValue(['response_bytes', request.response_bytes])"></span>
                                    <span x-show="request.redirect">Redirects to <span class="font-mono" x-text="request.redirect"></span></span>
                                </p>
                                <div :class="cls.tableWrap">
                                    <table class="w-full border-collapse text-[13px]"><tbody>
                                        <template x-for="[k, v] in headers(request.response_headers)" :key="k">
                                            <tr><td :class="cls.td" class="w-56 font-mono text-xs text-muted" x-text="k"></td><td :class="cls.td" class="font-mono text-xs" x-text="v"></td></tr>
                                        </template>
                                    </tbody></table>
                                </div>
                                <div class="flex gap-1.5" x-show="request.response_capture?.raw">
                                    <template x-for="v in [['pretty', 'Pretty'], ['raw', 'Raw']]" :key="v[0]">
                                        <button type="button" :class="cls.chip" @click="responseView = v[0]" :aria-pressed="responseView === v[0]" x-text="v[1]"></button>
                                    </template>
                                </div>
                                <pre class="scroll-quiet m-0 max-h-[480px] overflow-auto rounded-md border border-line bg-panel p-3 font-mono text-xs whitespace-pre-wrap [overflow-wrap:anywhere]" x-show="responseView === 'raw' && request.response_capture?.raw" x-text="body(request.response_capture?.raw, request.content_type)"></pre>
                                <template x-if="request.response_capture && responseView === 'pretty'">
                                    <div class="grid gap-3">
                                        <template x-for="(card, i) in request.response_capture.cards" :key="i">
                                            <div class="overflow-hidden rounded-md border border-line bg-panel">
                                                <div class="flex flex-wrap items-baseline gap-x-2 border-b border-line bg-sunk px-3 py-2">
                                                    <b class="text-[13px]" x-text="card.title"></b>
                                                    <span class="font-mono text-[11px] text-muted" x-text="card.subtitle ?? ''"></span>
                                                </div>
                                                <dl class="m-0">
                                                    <template x-for="[k, v] in Object.entries(card.items ?? {})" :key="k">
                                                        <div class="grid gap-1 border-b border-line px-3 py-2 last:border-b-0 sm:grid-cols-[120px_minmax(0,1fr)] sm:gap-3">
                                                            <dt class="text-xs text-muted" x-text="humanize(k)"></dt>
                                                            <dd class="m-0 min-w-0 font-mono text-xs [overflow-wrap:anywhere]" x-html="cardValue(v, k)"></dd>
                                                        </div>
                                                    </template>
                                                    <p class="m-0 px-3 py-2 text-xs text-muted" x-show="!Object.keys(card.items ?? {}).length">Nothing happened in this component.</p>
                                                </dl>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                </section>

                <section x-show="tab === 'session'">
                    <template x-if="session">
                        <div class="grid gap-4">
                            <p class="m-0 flex flex-wrap gap-x-4 gap-y-1 text-[13px] text-muted">
                                <span>Driver <b class="font-mono text-fg" x-text="session.driver"></b></span>
                                <span>Session <b class="font-mono text-fg" x-text="session.id"></b></span>
                                <span x-show="session.flash?.length">Flashed for the next request: <b class="font-mono text-fg" x-text="session.flash?.join(', ')"></b></span>
                            </p>
                            <div x-show="session.changes?.length">
                                <h3 :class="cls.sectionTitle">Changed by this request</h3>
                                <div class="p-1" :class="cls.tableWrap" x-html="changesTable(session.changes ?? [])"></div>
                            </div>
                            <p class="m-0 text-[13px] text-muted" x-show="session.changes && !session.changes.length">This request did not change the session.</p>
                            <h3 :class="cls.sectionTitle" class="mb-0">Contents</h3>
                            <div :class="cls.tableWrap" x-show="dot(session.data).length">
                                <table class="w-full border-collapse text-[13px]"><tbody>
                                    <template x-for="[k, v] in dot(session.data)" :key="k">
                                        <tr><td :class="cls.td" class="w-72 font-mono text-xs text-muted" x-text="k"></td><td :class="cls.td" class="font-mono text-xs" x-html="linked(v, 'data.' + k)"></td></tr>
                                    </template>
                                </tbody></table>
                            </div>
                            <p :class="cls.empty" x-show="!dot(session.data).length">The session was empty.</p>
                        </div>
                    </template>
                </section>

                <section x-show="tab === 'queries'">
                    <div class="mb-2.5 flex flex-wrap items-center gap-1.5 text-xs text-muted">Show
                        <template x-for="g in [['order', 'in order'], ['shape', 'by query'], ['caller', 'by caller'], ['table', 'by table'], ['transaction', 'by transaction']]" :key="g[0]">
                            <button type="button" :class="cls.chip" @click="groupBy = g[0]; openTab('queries')" :aria-pressed="groupBy === g[0]" x-text="g[1]"></button>
                        </template>
                    </div>
                    <div :class="cls.tableWrap" x-show="groupBy === 'order' && tabData?.rows?.length">
                        <table class="w-full border-collapse text-[13px]">
                            <thead><tr><th :class="cls.th" class="text-right">#</th><th :class="cls.th">Query</th><th :class="cls.th" class="text-right">At</th><th :class="cls.th" class="text-right">Time</th></tr></thead>
                            <tbody>
                                <template x-for="q in tabData?.rows ?? []" :key="q.n">
                                    <tr class="cursor-pointer hover:bg-sunk" :class="q.duplicate_of ? 'bg-warn-soft/60' : ''" @click="rowPopover($event, q, 'queries')">
                                        <td :class="cls.td" class="text-right font-mono text-xs text-muted tabular-nums" x-text="q.n"></td>
                                        <td :class="cls.td">
                                            <code class="block max-w-[680px] whitespace-pre-wrap font-mono text-xs [overflow-wrap:anywhere]" x-text="q.sql_with_bindings ?? q.sql"></code>
                                            <span class="mt-1 flex flex-wrap items-center gap-2">
                                                <span x-html="originHtml(q.origin)"></span>
                                                <span class="rounded bg-warn-soft px-1.5 font-mono text-[10.5px] text-warn" x-show="q.duplicate_of" x-text="'duplicate of #' + q.duplicate_of"></span>
                                                <span class="rounded bg-warn-soft px-1.5 font-mono text-[10.5px] text-warn" x-show="duplicates[q.n]" x-text="'repeated ' + duplicates[q.n] + '×'"></span>
                                                <span class="rounded bg-bad-soft px-1.5 font-mono text-[10.5px] text-bad" x-show="q.slow">slow</span>
                                            </span>
                                        </td>
                                        <td :class="cls.td" class="text-right font-mono text-xs text-muted tabular-nums" x-text="ms(q.time)"></td>
                                        <td :class="cls.td" class="text-right font-mono text-xs tabular-nums" x-text="ms(q.duration)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <div :class="cls.tableWrap" x-show="groupBy !== 'order' && tabData?.rows?.length">
                        <table class="w-full border-collapse text-[13px]">
                            <thead><tr><th :class="cls.th">Query</th><th :class="cls.th" class="text-right">Count</th><th :class="cls.th" class="text-right">Total</th><th :class="cls.th" class="text-right">Avg</th><th :class="cls.th" class="text-right">Max</th></tr></thead>
                            <tbody>
                                <template x-for="q in groupBy === 'order' ? [] : (tabData?.rows ?? [])" :key="q.key">
                                    <tr>
                                        <td :class="cls.td"><code class="block max-w-[680px] whitespace-pre-wrap font-mono text-xs [overflow-wrap:anywhere]" x-text="q.example ?? q.key"></code><span x-html="originHtml(q.origin)"></span></td>
                                        <td :class="cls.td" class="text-right tabular-nums" x-text="q.count"></td>
                                        <td :class="cls.td" class="text-right tabular-nums" x-text="ms(q.total_ms)"></td>
                                        <td :class="cls.td" class="text-right tabular-nums" x-text="ms(q.avg_ms)"></td>
                                        <td :class="cls.td" class="text-right tabular-nums" x-text="ms(q.max_ms)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <button type="button" class="mt-2.5" :class="cls.link" x-show="groupBy === 'order' && tabData?.next_offset != null" @click="loadMore()" x-text="'Show more (' + (tabData?.total - tabData?.rows?.length) + ' left)'"></button>
                </section>

                <section x-show="tab === 'trace'">
                    <div class="grid gap-1.5">
                        <template x-for="x in tabData?.captures ?? []" :key="x.id">
                            <div class="grid grid-cols-[minmax(0,1fr)_auto_auto] items-baseline gap-3 rounded-md border border-line bg-panel px-3 py-2" :class="x.id === selectedId ? 'border-brass shadow-[inset_3px_0_0_var(--brass)]' : ''" :style="`margin-left:${(x.depth ?? 0) * 24}px`">
                                <span class="flex min-w-0 items-baseline gap-2 [overflow-wrap:anywhere]">
                                    <span :class="cls.type" x-text="x.type"></span>
                                    <button type="button" :class="cls.link" x-show="x.id !== selectedId" @click="select(x.id)" x-text="x.name"></button>
                                    <span x-show="x.id === selectedId" x-text="x.name"></span>
                                </span>
                                <span class="font-mono text-xs" :class="statusClass(x.status)" x-text="x.status ?? ''"></span>
                                <span class="font-mono text-xs text-muted tabular-nums" x-text="ms(x.duration)"></span>
                            </div>
                        </template>
                    </div>
                </section>

                <section x-show="!['overview', 'timeline', 'queries', 'trace', 'request', 'session'].includes(tab)">
                    <div :class="cls.tableWrap" x-show="tabData?.rows?.length">
                        <table class="w-full border-collapse text-[13px]">
                            <thead><tr><template x-for="col in tableColumns" :key="col"><th :class="[cls.th, numeric(col) && '!text-right']" x-text="humanize(col.replace(/_(bytes|ms)$/, ''))"></th></template></tr></thead>
                            <tbody>
                                <template x-for="(row, i) in tabData?.rows ?? []" :key="i">
                                    <tr class="cursor-pointer hover:bg-sunk" :class="row.repeat_of || row.duplicate_of ? 'bg-warn-soft/60' : ''" @click="rowPopover($event, row, tab)"><template x-for="col in tableColumns" :key="col"><td :class="[cls.td, numeric(col) && '!text-right tabular-nums']" x-html="cell(row, col)"></td></template></tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <dl class="m-0 grid gap-1.5" x-show="tabData?.data">
                        <template x-for="[k, v] in Object.entries(tabData?.data ?? {})" :key="k">
                            <div class="grid grid-cols-[minmax(120px,220px)_minmax(0,1fr)] gap-3 border-b border-line py-1.5"><dt class="text-[12.5px] text-muted" x-text="k"></dt><dd class="m-0 font-mono text-xs [overflow-wrap:anywhere]" x-text="typeof v === 'object' ? JSON.stringify(v) : v"></dd></div>
                        </template>
                    </dl>
                    <button type="button" class="mt-2.5" :class="cls.link" x-show="tabData?.next_offset != null" @click="loadMore()" x-text="'Show more (' + (tabData?.total - tabData?.rows?.length) + ' left)'"></button>
                </section>
                <p class="text-xs text-muted" x-show="tabLoading">Loading...</p>
            </div>
        </template>
    </main>
</div>
</body>
</html>
