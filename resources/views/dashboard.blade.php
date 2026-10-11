<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex, nofollow" />
    <title>Monocle - {{ config('app.name') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ $assets['icon'] }}" />
    <link rel="stylesheet" href="{{ $assets['css'] }}" />
    <script defer src="{{ $assets['js'] }}"></script>
</head>
<body class="min-[861px]:h-dvh min-[861px]:overflow-hidden">
    <div
        class="flex min-h-dvh flex-col min-[861px]:grid min-[861px]:h-dvh min-[861px]:grid-cols-[minmax(280px,340px)_minmax(0,1fr)]"
        x-data="monocle(@js(['api' => $api, 'csrf' => csrf_token(), 'paths' => $paths]))"
        x-cloak
    >
        <aside
            class="border-line bg-panel flex min-h-0 min-w-0 shrink-0 flex-col border-b max-[860px]:max-h-[75dvh] min-[861px]:overflow-hidden min-[861px]:border-r min-[861px]:border-b-0"
            aria-label="Captures"
        >
            <div class="border-line flex flex-wrap items-center gap-x-2.5 gap-y-2 border-b px-4 pt-[18px] pb-3.5">
                <img class="block shrink-0" src="{{ $assets['icon'] }}" width="26" height="26" alt="" />
                <h1 class="m-0 text-lg font-bold tracking-[.01em]">Monocle</h1>
                <span class="text-muted ml-auto text-xs">{{ config('app.name') }} · {{ app()->environment() }}</span>
                <div class="border-line flex gap-0.5 rounded-full border p-0.5" role="group" aria-label="Theme">
                    <template x-for="option in ['system', 'light', 'dark']" :key="option">
                        <button
                            type="button"
                            class="text-muted aria-pressed:bg-brass aria-pressed:text-on-brass rounded-full px-2 py-0.5 text-[11px]"
                            @click="setTheme(option)"
                            :aria-pressed="theme === option"
                            x-text="option === 'system' ? 'Auto' : humanize(option)"
                        ></button>
                    </template>
                </div>
            </div>
            <button
                type="button"
                class="border-line flex items-center justify-between gap-2 border-b px-4 py-2.5 text-left text-[13px] min-[861px]:hidden"
                x-show="selectedId"
                @click="listOpen = ! listOpen"
                :aria-expanded="listOpen"
            >
                <span><span class="font-medium">Captures</span>
                    <span class="text-muted" x-text="'(' + total + ')'"></span
                ></span>
                <svg class="text-brass size-4 transition-transform duration-200" :class="listOpen && 'rotate-180'" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3.5 6 8 10.5 12.5 6" /></svg>
            </button>
            <div class="flex min-h-0 flex-1 flex-col" x-show="wide || ! selectedId || listOpen" x-collapse>
                <div class="border-line flex flex-col gap-2.5 border-b px-4 py-3">
                    <input
                        class="border-line bg-bg text-fg w-full rounded-md border px-2.5 py-2"
                        id="monocle-search"
                        type="search"
                        placeholder="Filter by path or name"
                        aria-label="Filter captures"
                        x-model.debounce.300ms="filters.q"
                    />
                    <div class="flex flex-wrap gap-1.5" role="group" aria-label="Capture type">
                        <template
                            x-for="
                                type in
                                [
                                    ['', 'All'],
                                    ['request', 'Requests'],
                                    ['job', 'Jobs'],
                                    ['command', 'Commands'],
                                    ['mcp', 'MCP'],
                                ]
                            "
                            :key="type[0]"
                        >
                            <button
                                type="button"
                                :class="cls.chip"
                                @click="filters.type = type[0]"
                                :aria-pressed="filters.type === type[0]"
                                x-text="type[1]"
                            ></button>
                        </template>
                    </div>
                    <div class="text-muted flex flex-wrap items-center gap-1.5 text-xs" x-show="hidden.length">
                        <span>Hidden</span>
                        <template x-for="name in hidden" :key="name">
                            <span class="border-line inline-flex items-center gap-1 rounded-full border py-0.5 pr-1 pl-2.5 font-mono text-[11px]">
                                <span x-text="name"></span>
                                <button
                                    type="button"
                                    class="text-muted hover:text-fg rounded-full px-1"
                                    @click="unhide(name)"
                                    :aria-label="'Show ' + name + ' again'"
                                >
                                    ×
                                </button>
                            </span>
                        </template>
                    </div>
                    <div class="text-muted flex flex-wrap items-center gap-x-3.5 gap-y-2 text-xs">
                        <label class="flex items-center gap-1.5"
                            ><input type="checkbox" id="monocle-errors" class="accent-brass" x-model="filters.errors" />
                            Only errors</label>
                        <label class="flex items-center gap-1.5"><input type="checkbox" id="monocle-live" class="accent-brass" x-model="live" /> Live</label>
                        <button
                            type="button"
                            class="ml-auto"
                            :class="cls.link"
                            x-show="! confirmClear"
                            @click="confirmClear = true"
                        >
                            Clear
                        </button>
                        <span class="ml-auto flex items-center gap-2" x-show="confirmClear">
                            Delete all captures?
                            <button type="button" class="text-bad underline underline-offset-[3px]" @click="clearAll()">
                                Delete
                            </button>
                            <button type="button" :class="cls.link" @click="confirmClear = false">Cancel</button>
                        </span>
                    </div>
                </div>
                <div class="scroll-quiet min-h-0 flex-1 overflow-y-auto overscroll-contain">
                    <template x-for="c in captures" :key="c.id">
                        <div class="group relative">
                            <button
                                type="button"
                                class="border-line hover:bg-sunk aria-[current=true]:bg-brass-soft grid w-full grid-cols-[auto_minmax(0,1fr)_auto] items-baseline gap-x-2.5 gap-y-1 border-b px-4 py-2.5 text-left aria-[current=true]:shadow-[inset_3px_0_0_var(--brass)]"
                                @click="select(c.id)"
                                :aria-current="c.id === selectedId"
                            >
                                <span :class="cls.type" x-text="c.type"></span>
                                <span
                                    class="overflow-hidden font-medium text-ellipsis whitespace-nowrap"
                                    x-text="c.name"
                                ></span>
                                <span class="font-mono text-xs tabular-nums" x-text="ms(c.duration)"></span>
                                <span class="text-muted col-span-2 col-start-2 flex flex-wrap gap-2.5 text-xs">
                                    <span
                                        class="font-mono"
                                        :class="statusClass(c.status)"
                                        x-text="c.status ?? ''"
                                    ></span>
                                    <span
                                        x-show="c.counts?.queries"
                                        x-text="c.counts?.queries + (c.counts?.queries === 1 ? ' query' : ' queries')"
                                    ></span>
                                    <span
                                        class="text-warn"
                                        x-show="c.warnings"
                                        x-text="c.warnings + (c.warnings === 1 ? ' warning' : ' warnings')"
                                    ></span>
                                    <span x-show="c.has_errors" class="text-bad"
                                        ><i class="bg-bad inline-block size-[7px] rounded-full"></i> error</span>
                                    <span
                                        x-show="c.label"
                                        class="border-brass text-brass rounded-full border border-dashed px-2"
                                        x-text="c.label"
                                    ></span>
                                    <span x-text="ago(c.started_at)"></span>
                                </span>
                            </button>
                            <button
                                type="button"
                                class="text-muted hover:text-fg absolute right-3 bottom-2 rounded px-1.5 text-[11px] opacity-0 group-hover:opacity-100 focus-visible:opacity-100 max-[860px]:opacity-100"
                                @click.stop="hide(c.route ?? c.name)"
                                :data-tip="'Hide ' + (c.route ?? c.name)"
                                x-text="
                                    'hide ' +
                                    ({ request: 'route', command: 'command', job: 'job', mcp: 'call' }[c.type] ??
                                        'these')
                                "
                            ></button>
                        </div>
                    </template>
                    <p
                        class="m-4"
                        :class="cls.empty"
                        x-show="! captures.length && ! error"
                        x-text="
                            filters.type || filters.q || filters.errors || hidden.length
                                ? 'No captures match these filters.'
                                : 'No captures yet. Open a page, run a job or a command, and it shows up here.'
                        "
                    ></p>
                    <p class="m-4" :class="cls.empty" x-show="error" x-text="error"></p>
                </div>
            </div>
        </aside>

        <main
            class="scroll-quiet flex min-h-0 min-w-0 flex-1 flex-col gap-5 px-4 pt-5 pb-10 min-[861px]:[scrollbar-gutter:stable] min-[861px]:overflow-y-auto min-[861px]:overscroll-contain min-[861px]:px-6"
            x-ref="detail"
            :aria-busy="tabLoading"
        >
            <p class="sr-only" role="status" aria-live="polite" x-text="status"></p>
            <p :class="cls.empty" x-show="! capture">Select a capture.</p>
            <template x-if="capture">
                <div class="flex min-w-0 flex-col gap-5">
                    <header class="flex flex-wrap items-center gap-x-3 gap-y-2.5">
                        <h2
                            class="m-0 min-w-0 text-2xl font-bold text-balance [overflow-wrap:anywhere]"
                            x-text="summary.name"
                        ></h2>
                        <span
                            class="font-mono text-xs"
                            :class="statusClass(summary.status)"
                            x-text="
                                summary.type === 'command' && summary.status !== null
                                    ? 'exit ' + summary.status
                                    : (summary.status ?? '')
                            "
                        ></span>
                        <span
                            class="border-line text-muted rounded-full border px-2 font-mono text-xs"
                            x-show="capture.context?.route"
                            x-text="capture.context?.route"
                            data-tip="Route name"
                        ></span>
                        <span
                            class="border-brass text-brass rounded-full border border-dashed px-2 text-xs"
                            x-show="summary.label"
                            x-text="'label: ' + summary.label"
                        ></span>
                    </header>
                    <div class="text-muted flex flex-wrap items-center gap-x-[18px] gap-y-1.5 text-[13px]">
                        <span
                            ><b
                                class="text-fg font-semibold tabular-nums"
                                x-text="ms(summary.totals?.duration_ms ?? model?.duration)"
                            ></b>
                            total</span>
                        <span x-text="summary.type"></span>
                        <span
                            class="font-mono text-xs [overflow-wrap:anywhere]"
                            x-show="capture.context?.action && capture.context.action !== 'Closure'"
                            x-html="linkHtml(capture.context?.action, capture.context?.action_link)"
                        ></span>
                        <span
                            class="font-mono text-xs [overflow-wrap:anywhere]"
                            x-show="capture.context?.command_class"
                            x-html="linkHtml(capture.context?.command_class, capture.context?.command_link)"
                        ></span>
                        <span class="inline-flex items-center gap-1">
                            <span class="font-mono text-xs select-all" x-text="capture.id"></span>
                            <button
                                type="button"
                                class="text-muted hover:text-brass rounded p-0.5"
                                @click="copy(capture.id, 'id')"
                                :data-tip="copied === 'id' ? 'Copied' : 'Copy id'"
                                :aria-label="copied === 'id' ? 'Copied' : 'Copy capture id'"
                            >
                                <svg
                                    x-show="copied !== 'id'"
                                    class="size-3.5"
                                    viewBox="0 0 16 16"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.5"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    aria-hidden="true"
                                >
                                    <rect x="5.5" y="5.5" width="8" height="8" rx="1.5" />
                                    <path d="M10.5 5.5V3.5A1.5 1.5 0 0 0 9 2H3.5A1.5 1.5 0 0 0 2 3.5V9a1.5 1.5 0 0 0 1.5 1.5h2" />
                                </svg>
                                <svg
                                    x-show="copied === 'id'"
                                    x-cloak
                                    class="text-ok size-3.5"
                                    viewBox="0 0 16 16"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    aria-hidden="true"
                                ><path d="M3 8.5 6.5 12 13 4.5" /></svg>
                            </button>
                        </span>
                        <span x-text="summary.started_at ? ago(summary.started_at) : ''"></span>
                        <button
                            type="button"
                            class="border-line text-muted hover:border-brass hover:text-brass ml-auto inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs"
                            @click="copy(agentPrompt, 'prompt')"
                            data-tip="Copy a prompt that asks your coding agent to look into this capture through the Monocle MCP server"
                        >
                            <svg class="size-3.5" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.5 3.5h11v7h-6l-3 2.5v-2.5h-2z" /></svg>
                            <span x-text="copied === 'prompt' ? 'Copied' : 'Copy agent prompt'"></span>
                        </button>
                    </div>
                    <div
                        class="flex flex-wrap gap-2 text-[13px]"
                        x-show="summary.parent_id || summary.children?.length"
                    >
                        <span x-show="summary.parent_id"
                            >Dispatched by
                            <button type="button" :class="cls.link" @click="select(summary.parent_id)">
                                parent capture
                            </button></span>
                        <span x-show="summary.children?.length"
                            >Dispatched
                            <button
                                type="button"
                                :class="cls.link"
                                @click="openTab('trace')"
                                x-text="summary.children?.length + ' job(s)'"
                            ></button
                        ></span>
                    </div>

                    <nav
                        class="scroll-quiet border-line flex snap-x snap-mandatory scroll-px-2 gap-0.5 overflow-x-auto border-b"
                        role="tablist"
                        aria-label="Capture sections"
                        x-ref="tabs"
                    >
                        <template x-for="t in tabs" :key="t.key">
                            <button
                                type="button"
                                class="group text-muted aria-selected:border-brass aria-selected:text-fg flex shrink-0 snap-start items-center gap-1.5 border-b-2 border-transparent px-3 py-2 whitespace-nowrap aria-selected:font-semibold"
                                role="tab"
                                :id="'monocle-tab-' + t.key"
                                :aria-controls="panelId(t.key)"
                                :tabindex="tab === t.key ? 0 : -1"
                                :data-tip="t.description ?? ''"
                                @click="openTab(t.key)"
                                @keydown.right.prevent="stepTab(1)"
                                @keydown.left.prevent="stepTab(-1)"
                                :aria-selected="tab === t.key"
                            >
                                <span
                                    class="grid after:invisible after:h-0 after:overflow-hidden after:font-semibold after:content-[attr(data-label)]"
                                    :data-label="t.label"
                                    x-text="t.label"
                                ></span
                                ><span
                                    class="bg-sunk text-muted group-aria-selected:bg-brass group-aria-selected:text-on-brass min-w-5 rounded-full px-1.5 py-px text-center font-mono text-[10.5px] leading-4 tabular-nums"
                                    x-show="t.count"
                                    x-text="t.count"
                                ></span>
                            </button>
                        </template>
                    </nav>

                    <section
                        id="monocle-panel-overview"
                        role="tabpanel"
                        :aria-labelledby="'monocle-tab-' + tab"
                        tabindex="0"
                        x-show="tab === 'overview'"
                        class="grid grid-cols-1 gap-6 min-[1101px]:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]"
                    >
                        <div class="grid min-w-0 content-start gap-6">
                            <div x-show="phases.length">
                                <h3 :class="cls.sectionTitle">Where the time went</h3>
                                <div
                                    class="bg-sunk flex h-[26px] overflow-hidden rounded"
                                    role="img"
                                    :aria-label="'Time per phase: ' +
                                    phases.map((p) => p.name + ' ' + ms(p.ms)).join(', ')"
                                >
                                    <template x-for="p in phases" :key="p.name">
                                        <span
                                            class="h-full min-w-0.5"
                                            :data-tip="p.name + ': ' + ms(p.ms)"
                                            :style="`width:${p.percent}%;background:${phaseColor(p.name)}`"
                                        ></span>
                                    </template>
                                </div>
                                <div class="mt-3 grid grid-cols-[repeat(auto-fill,minmax(150px,1fr))] gap-x-4 gap-y-2">
                                    <template x-for="p in phases" :key="p.name">
                                        <div class="grid grid-cols-[10px_minmax(0,1fr)_auto] items-baseline gap-x-2 text-[13px]">
                                            <i
                                                class="size-2.5 self-center rounded-sm"
                                                :style="`background:${phaseColor(p.name)}`"
                                            ></i>
                                            <span><span x-text="p.name"></span>
                                                <span
                                                    class="text-muted text-xs"
                                                    x-show="p.count > 1"
                                                    x-text="'×' + p.count"
                                                ></span
                                            ></span>
                                            <span class="font-mono text-xs tabular-nums" x-text="ms(p.ms)"></span>
                                            <span
                                                class="text-muted col-span-2 col-start-2 -mt-1 text-xs"
                                                x-text="p.percent + ' %'"
                                            ></span>
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
                                            <div class="bg-sunk h-2 overflow-hidden rounded">
                                                <span
                                                    class="block h-full rounded"
                                                    :style="`width:${(c.ms / maxCategory) * 100}%;background:${categoryColor(c.name)}`"
                                                ></span>
                                            </div>
                                            <span
                                                class="text-muted col-start-2 font-mono text-xs tabular-nums sm:col-start-auto sm:text-right"
                                                x-text="ms(c.ms) + ' · ' + c.count + (c.count === 1 ? ' op' : ' ops')"
                                            ></span>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                        <div class="grid min-w-0 content-start gap-6">
                            <div>
                                <h3 :class="cls.sectionTitle">Warnings</h3>
                                <div class="grid gap-2" x-show="summary.warnings?.length">
                                    <template x-for="(w, wi) in summary.warnings" :key="w">
                                        <div class="bg-warn-soft grid grid-cols-[auto_minmax(0,1fr)] gap-x-2.5 rounded-md px-3 py-2.5">
                                            <span class="text-warn pt-0.5 font-mono text-[11px]">!</span>
                                            <span
                                                class="[overflow-wrap:anywhere]"
                                                x-html="linkText(w, capture.warning_links?.[wi])"
                                            ></span>
                                        </div>
                                    </template>
                                </div>
                                <p :class="cls.empty" x-show="! summary.warnings?.length">No warnings.</p>
                            </div>
                            <div>
                                <h3 :class="cls.sectionTitle">Totals</h3>
                                <div class="grid gap-4">
                                    <template x-for="g in totalGroups" :key="g.label">
                                        <div class="border-line grid gap-2 border-t pt-3 first:border-t-0 first:pt-0">
                                            <h4 class="text-brass m-0 text-xs font-semibold" x-text="g.label"></h4>
                                            <div class="grid grid-cols-[repeat(auto-fill,minmax(120px,1fr))] gap-x-4 gap-y-3">
                                                <template x-for="t in g.items" :key="t[0]">
                                                    <div>
                                                        <div
                                                            class="text-muted text-xs"
                                                            x-text="totalLabel(t[0], g.label)"
                                                        ></div>
                                                        <div
                                                            class="text-xl font-bold tabular-nums"
                                                            x-text="totalValue(t)"
                                                        ></div>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section
                        id="monocle-panel-timeline"
                        role="tabpanel"
                        :aria-labelledby="'monocle-tab-' + tab"
                        tabindex="0"
                        x-show="tab === 'timeline'"
                        @click="timelineClick($event)"
                        x-html="timelineHtml"
                    ></section>

                    <section
                        id="monocle-panel-request"
                        role="tabpanel"
                        :aria-labelledby="'monocle-tab-' + tab"
                        tabindex="0"
                        x-show="tab === 'request'"
                        class="grid gap-6"
                    >
                        <template x-if="request">
                            <div class="grid gap-6">
                                <div class="grid gap-2">
                                    <h3 :class="cls.sectionTitle" class="mb-0">Request</h3>
                                    <p class="m-0 font-mono text-[13px] [overflow-wrap:anywhere]">
                                        <b x-text="request.method"></b> <span x-text="request.url"></span>
                                        <span x-html="copyButton(request.url, 'url')"></span>
                                    </p>
                                    <dl class="m-0 grid gap-x-4 gap-y-1 text-[13px] sm:grid-cols-[160px_minmax(0,1fr)]">
                                        <template
                                            x-for="
                                                [k, v, path] in
                                                [
                                                    ['Route', request.route?.name ?? request.route?.uri, 'route.name'],
                                                    ['Action', request.route?.action, 'route.action'],
                                                ].filter(([, v]) => v)
                                            "
                                            :key="k"
                                        >
                                            <div class="contents">
                                                <dt class="text-muted" x-text="k"></dt>
                                                <dd
                                                    class="group/copy m-0 font-mono text-xs [overflow-wrap:anywhere]"
                                                    x-html="
                                                        linked(v, path) +
                                                        ' ' +
                                                        copyButton(String(v), k.toLowerCase(), { hover: true })
                                                    "
                                                ></dd>
                                            </div>
                                        </template>
                                        <template x-if="authUser">
                                            <div class="contents">
                                                <dt class="text-muted">User</dt>
                                                <dd class="m-0 font-mono text-xs [overflow-wrap:anywhere]">
                                                    <span x-html="maybeLink(authUser.user, authUser.link)"></span>
                                                    <span class="text-muted" x-text="'via ' + authUser.via"></span>
                                                </dd>
                                            </div>
                                        </template>
                                        <template
                                            x-for="
                                                [k, v, path] in
                                                [
                                                    ['IP', request.ip, 'ip'],
                                                    ['User agent', request.user_agent, 'user_agent'],
                                                    ['Locale', request.locale, 'locale'],
                                                ].filter(([, v]) => v)
                                            "
                                            :key="k"
                                        >
                                            <div class="contents">
                                                <dt class="text-muted" x-text="k"></dt>
                                                <dd
                                                    class="group/copy m-0 font-mono text-xs [overflow-wrap:anywhere]"
                                                    x-html="
                                                        linked(v, path) +
                                                        ' ' +
                                                        copyButton(String(v), k.toLowerCase(), { hover: true })
                                                    "
                                                ></dd>
                                            </div>
                                        </template>
                                    </dl>
                                </div>

                                <div x-show="authEvents.length">
                                    <h3 :class="cls.sectionTitle">Authentication</h3>
                                    <div :class="cls.tableWrap">
                                        <table class="w-full border-collapse text-[13px]">
                                            <thead>
                                                <tr>
                                                    <th scope="col" :class="cls.th">Event</th>
                                                    <th scope="col" :class="cls.th">Guard</th>
                                                    <th scope="col" :class="cls.th">User</th>
                                                    <th scope="col" :class="cls.th">Details</th>
                                                    <th scope="col" :class="cls.th">Origin</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <template x-for="(row, i) in authEvents" :key="i">
                                                    <tr
                                                        class="hover:bg-sunk cursor-pointer"
                                                        @click="rowPopover($event, row, 'auth')"
                                                    >
                                                        <td
                                                            :class="cls.td"
                                                            :class="['failed', 'lockout'].includes(row.event) &&
                                                            'text-warn font-medium'"
                                                            x-text="row.event"
                                                        ></td>
                                                        <td :class="cls.td" x-text="row.guard ?? ''"></td>
                                                        <td :class="cls.td" x-html="cell(row, 'user')"></td>
                                                        <td
                                                            :class="cls.td"
                                                            class="font-mono text-xs"
                                                            x-text="
                                                                [
                                                                    row.credentials?.length
                                                                        ? 'tried ' + row.credentials.join(', ')
                                                                        : null,
                                                                    row.remember ? 'remember' : null,
                                                                    row.ip,
                                                                    row.abilities?.length
                                                                        ? 'abilities ' + row.abilities.join(', ')
                                                                        : null,
                                                                ]
                                                                    .filter(Boolean)
                                                                    .join(' · ')
                                                            "
                                                        ></td>
                                                        <td :class="cls.td" x-html="cell(row, 'origin')"></td>
                                                    </tr>
                                                </template>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div>
                                    <h3 :class="cls.sectionTitle">Payload</h3>
                                    <div
                                        :class="cls.tableWrap"
                                        x-show="dot(request.query).length || dot(request.input).length"
                                    >
                                        <table class="w-full border-collapse text-[13px]">
                                            <thead>
                                                <tr>
                                                    <th scope="col" :class="cls.th">Key</th>
                                                    <th scope="col" :class="cls.th">Value</th>
                                                    <th scope="col" :class="cls.th" class="max-sm:hidden">From</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <template
                                                    x-for="
                                                        [k, v, from] in
                                                        [
                                                            ...dot(request.query).map((e) => [...e, 'query']),
                                                            ...dot(request.input).map((e) => [...e, 'body']),
                                                        ]
                                                    "
                                                    :key="from + k"
                                                >
                                                    <tr>
                                                        <td
                                                            :class="cls.td"
                                                            class="min-w-32 font-mono text-xs break-words"
                                                        >
                                                            <span x-text="k"></span
                                                            ><span
                                                                class="text-muted block text-[10px] sm:hidden"
                                                                x-text="from"
                                                            ></span>
                                                        </td>
                                                        <td
                                                            :class="cls.td"
                                                            class="group/copy font-mono text-xs"
                                                            x-html="
                                                                payloadValue(v) +
                                                                ' ' +
                                                                copyButton(
                                                                    typeof v === 'object'
                                                                        ? JSON.stringify(v)
                                                                        : String(v),
                                                                    k,
                                                                    { hover: true },
                                                                )
                                                            "
                                                        ></td>
                                                        <td
                                                            :class="cls.td"
                                                            class="text-muted text-xs max-sm:hidden"
                                                            x-text="from"
                                                        ></td>
                                                    </tr>
                                                </template>
                                            </tbody>
                                        </table>
                                    </div>
                                    <p
                                        :class="cls.empty"
                                        x-show="
                                            ! dot(request.query).length &&
                                            ! dot(request.input).length &&
                                            ! request.files?.length
                                        "
                                    >
                                        No query string or body.
                                    </p>
                                    <div class="mt-3" :class="cls.tableWrap" x-show="request.files?.length">
                                        <table class="w-full border-collapse text-[13px]">
                                            <thead>
                                                <tr>
                                                    <th scope="col" :class="cls.th">File field</th>
                                                    <th scope="col" :class="cls.th">Name</th>
                                                    <th scope="col" :class="cls.th">Type</th>
                                                    <th scope="col" :class="cls.th" class="text-right">Size</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <template x-for="f in request.files ?? []" :key="f.key + f.name">
                                                    <tr>
                                                        <td
                                                            :class="cls.td"
                                                            class="font-mono text-xs"
                                                            x-text="f.key"
                                                        ></td>
                                                        <td :class="cls.td" x-text="f.name"></td>
                                                        <td
                                                            :class="cls.td"
                                                            class="text-muted text-xs"
                                                            x-text="f.mime"
                                                        ></td>
                                                        <td
                                                            :class="cls.td"
                                                            class="text-right tabular-nums"
                                                            x-text="f.size"
                                                        ></td>
                                                    </tr>
                                                </template>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div>
                                    <h3 :class="cls.sectionTitle">Request headers</h3>
                                    <div :class="cls.tableWrap">
                                        <table class="w-full border-collapse text-[13px]">
                                            <tbody>
                                                <template x-for="[k, v] in headers(request.headers)" :key="k">
                                                    <tr>
                                                        <td
                                                            :class="cls.td"
                                                            class="text-muted font-mono text-xs ![overflow-wrap:normal] whitespace-nowrap"
                                                            :data-tip="k.length > 32 ? k : ''"
                                                            x-text="shortName(k)"
                                                        ></td>
                                                        <td :class="cls.td" class="group/copy font-mono text-xs">
                                                            <span x-text="v"></span>
                                                            <span x-html="copyButton(String(v), k, { hover: true })"></span>
                                                        </td>
                                                    </tr>
                                                </template>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div x-show="dot(request.cookies).length">
                                    <h3 :class="cls.sectionTitle">Cookies</h3>
                                    <div :class="cls.tableWrap">
                                        <table class="w-full border-collapse text-[13px]">
                                            <tbody>
                                                <template x-for="[k, v] in dot(request.cookies)" :key="k">
                                                    <tr>
                                                        <td
                                                            :class="cls.td"
                                                            class="text-muted font-mono text-xs ![overflow-wrap:normal] whitespace-nowrap"
                                                            :data-tip="k.length > 32 ? k : ''"
                                                            x-text="shortName(k)"
                                                        ></td>
                                                        <td :class="cls.td" class="group/copy font-mono text-xs">
                                                            <span x-text="v"></span>
                                                            <span x-html="copyButton(String(v), k, { hover: true })"></span>
                                                        </td>
                                                    </tr>
                                                </template>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div class="grid gap-3">
                                    <h3 :class="cls.sectionTitle" class="mb-0">Response</h3>
                                    <p class="m-0 flex flex-wrap gap-x-4 gap-y-1 text-[13px]">
                                        <span
                                            class="font-mono"
                                            :class="statusClass(request.status)"
                                            x-text="request.status"
                                        ></span>
                                        <span class="text-muted" x-text="request.content_type"></span>
                                        <span
                                            class="text-muted tabular-nums"
                                            x-show="request.response_bytes != null"
                                            x-text="totalValue(['response_bytes', request.response_bytes])"
                                        ></span>
                                        <span x-show="request.redirect"
                                            >Redirects to <span class="font-mono" x-text="request.redirect"></span
                                        ></span>
                                    </p>
                                    <div :class="cls.tableWrap">
                                        <table class="w-full border-collapse text-[13px]">
                                            <tbody>
                                                <template x-for="[k, v] in headers(request.response_headers)" :key="k">
                                                    <tr>
                                                        <td
                                                            :class="cls.td"
                                                            class="text-muted font-mono text-xs ![overflow-wrap:normal] whitespace-nowrap"
                                                            :data-tip="k.length > 32 ? k : ''"
                                                            x-text="shortName(k)"
                                                        ></td>
                                                        <td :class="cls.td" class="group/copy font-mono text-xs">
                                                            <span x-text="v"></span>
                                                            <span x-html="copyButton(String(v), k, { hover: true })"></span>
                                                        </td>
                                                    </tr>
                                                </template>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="flex gap-1.5" x-show="request.response_capture?.raw">
                                        <template
                                            x-for="
                                                v in
                                                [
                                                    ['pretty', 'Pretty'],
                                                    ['raw', 'Raw'],
                                                ]
                                            "
                                            :key="v[0]"
                                        >
                                            <button
                                                type="button"
                                                :class="cls.chip"
                                                @click="responseView = v[0]"
                                                :aria-pressed="responseView === v[0]"
                                                x-text="v[1]"
                                            ></button>
                                        </template>
                                        <span
                                            class="ml-auto"
                                            x-show="request.response_capture?.raw"
                                            x-html="copyButton(request.response_capture?.raw ?? '', 'response body')"
                                        ></span>
                                    </div>
                                    <pre
                                        class="scroll-quiet border-line bg-panel m-0 max-h-[480px] overflow-auto rounded-md border p-3 font-mono text-xs [overflow-wrap:anywhere] whitespace-pre-wrap"
                                        x-show="responseView === 'raw' && request.response_capture?.raw"
                                        x-text="body(request.response_capture?.raw, request.content_type)"
                                    ></pre>
                                    <template x-if="request.response_capture && responseView === 'pretty'">
                                        <div class="grid gap-3">
                                            <template x-for="(card, i) in request.response_capture.cards" :key="i">
                                                <div class="border-line bg-panel overflow-hidden rounded-md border">
                                                    <div class="border-line bg-sunk flex flex-wrap items-baseline gap-x-2 border-b px-3 py-2">
                                                        <b class="text-[13px]" x-text="card.title"></b>
                                                        <span
                                                            class="text-muted font-mono text-[11px]"
                                                            x-text="card.subtitle ?? ''"
                                                        ></span>
                                                    </div>
                                                    <dl class="m-0">
                                                        <template
                                                            x-for="[k, v] in Object.entries(card.items ?? {})"
                                                            :key="k"
                                                        >
                                                            <div class="border-line grid gap-1 border-b px-3 py-2 last:border-b-0 sm:grid-cols-[120px_minmax(0,1fr)] sm:gap-3">
                                                                <dt
                                                                    class="text-muted text-xs"
                                                                    x-text="humanize(k)"
                                                                ></dt>
                                                                <dd
                                                                    class="m-0 min-w-0 font-mono text-xs [overflow-wrap:anywhere]"
                                                                    x-html="cardValue(v, k)"
                                                                ></dd>
                                                            </div>
                                                        </template>
                                                        <p
                                                            class="text-muted m-0 px-3 py-2 text-xs"
                                                            x-show="! Object.keys(card.items ?? {}).length"
                                                        >
                                                            Nothing happened in this component.
                                                        </p>
                                                    </dl>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </section>

                    <section
                        id="monocle-panel-session"
                        role="tabpanel"
                        :aria-labelledby="'monocle-tab-' + tab"
                        tabindex="0"
                        x-show="tab === 'session'"
                    >
                        <template x-if="session">
                            <div class="grid gap-4">
                                <p class="text-muted m-0 flex flex-wrap gap-x-4 gap-y-1 text-[13px]">
                                    <span>Driver <b class="text-fg font-mono" x-text="session.driver"></b></span>
                                    <span
                                        >Session <b class="text-fg font-mono" x-text="session.id"></b>
                                        <span x-html="copyButton(session.id ?? '', 'session id')"></span
                                    ></span>
                                    <span x-show="session.flash?.length"
                                        >Flashed for the next request:
                                        <b class="text-fg font-mono" x-text="session.flash?.join(', ')"></b
                                    ></span>
                                </p>
                                <div x-show="session.changes?.length">
                                    <h3 :class="cls.sectionTitle">Changed by this request</h3>
                                    <div
                                        class="p-1"
                                        :class="cls.tableWrap"
                                        x-html="changesTable(session.changes ?? [])"
                                    ></div>
                                </div>
                                <p
                                    class="text-muted m-0 text-[13px]"
                                    x-show="session.changes && ! session.changes.length"
                                >
                                    This request did not change the session.
                                </p>
                                <h3 :class="cls.sectionTitle" class="mb-0">Contents</h3>
                                <div :class="cls.tableWrap" x-show="dot(session.data).length">
                                    <table class="w-full border-collapse text-[13px]">
                                        <tbody>
                                            <template x-for="[k, v] in dot(session.data)" :key="k">
                                                <tr>
                                                    <td
                                                        :class="cls.td"
                                                        class="text-muted w-72 font-mono text-xs"
                                                        x-text="k"
                                                    ></td>
                                                    <td
                                                        :class="cls.td"
                                                        class="group/copy font-mono text-xs"
                                                        x-html="
                                                            linked(v, 'data.' + k) +
                                                            ' ' +
                                                            copyButton(
                                                                typeof v === 'object' ? JSON.stringify(v) : String(v),
                                                                k,
                                                                { hover: true },
                                                            )
                                                        "
                                                    ></td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                                <p :class="cls.empty" x-show="! dot(session.data).length">The session was empty.</p>
                            </div>
                        </template>
                    </section>

                    <section
                        id="monocle-panel-queries"
                        role="tabpanel"
                        :aria-labelledby="'monocle-tab-' + tab"
                        tabindex="0"
                        x-show="tab === 'queries'"
                    >
                        <div class="text-muted mb-2.5 flex flex-wrap items-center gap-1.5 text-xs">
                            Show
                            <template
                                x-for="
                                    g in
                                    [
                                        ['order', 'in order'],
                                        ['shape', 'by query'],
                                        ['caller', 'by caller'],
                                        ['table', 'by table'],
                                        ['transaction', 'by transaction'],
                                    ]
                                "
                                :key="g[0]"
                            >
                                <button
                                    type="button"
                                    :class="cls.chip"
                                    @click="
                                        groupBy = g[0];
                                        openTab('queries');
                                    "
                                    :aria-pressed="groupBy === g[0]"
                                    x-text="g[1]"
                                ></button>
                            </template>
                        </div>
                        <div :class="cls.tableWrap" x-show="groupBy === 'order' && tabData?.rows?.length">
                            <table class="w-full border-collapse text-[13px]">
                                <thead>
                                    <tr>
                                        <th scope="col" :class="cls.th" class="w-0 !text-center whitespace-nowrap">
                                            #
                                        </th>
                                        <th scope="col" :class="cls.th">Query</th>
                                        <th scope="col" :class="cls.th">Connection</th>
                                        <th scope="col" :class="[cls.th, '!text-right']">Duration</th>
                                    </tr>
                                </thead>
                                <template x-for="q in tabData?.rows ?? []" :key="q.n">
                                    <tbody>
                                        <tr
                                            class="hover:bg-sunk cursor-pointer"
                                            :class="q.duplicate_of ? 'bg-warn-soft/60' : ''"
                                            @click="toggleQuery(q.n)"
                                        >
                                            <td
                                                :class="cls.td"
                                                class="text-muted w-0 font-mono text-xs whitespace-nowrap tabular-nums"
                                            >
                                                <button
                                                    type="button"
                                                    class="inline-flex cursor-pointer items-center gap-1"
                                                    :aria-expanded="openQueries.includes(q.n)"
                                                    :aria-label="(openQueries.includes(q.n) ? 'Hide' : 'Show') +
                                                    ' the details of query ' +
                                                    q.n"
                                                    @click.stop="toggleQuery(q.n)"
                                                >
                                                    <svg
                                                        class="text-brass size-3 transition-transform duration-200"
                                                        :class="openQueries.includes(q.n) && 'rotate-90'"
                                                        viewBox="0 0 16 16"
                                                        fill="none"
                                                        stroke="currentColor"
                                                        stroke-width="2"
                                                        stroke-linecap="round"
                                                        stroke-linejoin="round"
                                                        aria-hidden="true"
                                                    >
                                                        <path d="M6 3.5 10.5 8 6 12.5" />
                                                    </svg>
                                                    <span x-text="q.n"></span>
                                                </button>
                                            </td>
                                            <td :class="cls.td">
                                                <span class="group/copy flex items-start gap-1.5">
                                                    <code
                                                        class="block max-w-[680px] font-mono text-xs [overflow-wrap:anywhere] whitespace-pre-wrap"
                                                        x-text="q.sql_with_bindings ?? q.sql"
                                                    ></code>
                                                    <span
                                                        x-html="
                                                            copyButton(q.sql_with_bindings ?? q.sql, 'query', {
                                                                hover: true,
                                                            })
                                                        "
                                                    ></span>
                                                </span>
                                                <span class="mt-1 flex flex-wrap items-center gap-2">
                                                    <span x-html="originHtml(q.origin)"></span>
                                                    <span
                                                        class="bg-warn-soft text-warn rounded px-1.5 font-mono text-[10.5px]"
                                                        x-show="q.duplicate_of"
                                                        x-text="'duplicate of #' + q.duplicate_of"
                                                    ></span>
                                                    <span
                                                        class="bg-warn-soft text-warn rounded px-1.5 font-mono text-[10.5px]"
                                                        x-show="duplicates[q.n]"
                                                        x-text="'repeated ' + duplicates[q.n] + '×'"
                                                    ></span>
                                                    <span
                                                        class="bg-bad-soft text-bad rounded px-1.5 font-mono text-[10.5px]"
                                                        x-show="q.slow"
                                                    >slow</span>
                                                </span>
                                            </td>
                                            <td
                                                :class="cls.td"
                                                class="text-muted font-mono text-xs whitespace-nowrap"
                                                x-text="q.connection ?? ''"
                                            ></td>
                                            <td
                                                :class="cls.td"
                                                class="text-right tabular-nums"
                                                x-html="
                                                    q.duration == null
                                                        ? `<span class='text-muted'>-</span><span class='block whitespace-nowrap text-[11px] text-muted'>at ${ms(q.time)}</span>`
                                                        : cell(q, 'duration')
                                                "
                                            ></td>
                                        </tr>
                                        <tr x-show="openQueries.includes(q.n)" x-cloak>
                                            <td colspan="4" class="border-line bg-sunk/50 border-b px-3 py-0">
                                                <div x-show="openQueries.includes(q.n)" x-collapse>
                                                    <div class="py-3" x-html="queryDetails(q)"></div>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </template>
                            </table>
                        </div>
                        <div :class="cls.tableWrap" x-show="groupBy !== 'order' && tabData?.rows?.length">
                            <table class="w-full border-collapse text-[13px]">
                                <thead>
                                    <tr>
                                        <th
                                            scope="col"
                                            :class="cls.th"
                                            x-text="
                                                {
                                                    shape: 'Query',
                                                    caller: 'Caller',
                                                    table: 'Table',
                                                    transaction: 'Transaction',
                                                }[groupBy] ?? 'Query'
                                            "
                                        ></th>
                                        <th scope="col" :class="[cls.th, '!text-right']">Count</th>
                                        <th scope="col" :class="[cls.th, '!text-right']">Total</th>
                                        <th scope="col" :class="[cls.th, '!text-right']">Avg</th>
                                        <th scope="col" :class="[cls.th, '!text-right']">Max</th>
                                    </tr>
                                </thead>
                                <template x-for="q in groupBy === 'order' ? [] : (tabData?.rows ?? [])" :key="q.key">
                                    <tbody>
                                        <tr class="hover:bg-sunk group/copy cursor-pointer" @click="toggleGroup(q.key)">
                                            <td :class="cls.td">
                                                <div class="flex items-start gap-1.5">
                                                    <button
                                                        type="button"
                                                        class="text-brass mt-0.5 shrink-0 cursor-pointer"
                                                        :aria-expanded="openGroups.includes(q.key)"
                                                        :aria-label="(openGroups.includes(q.key) ? 'Hide' : 'Show') +
                                                        ' the ' +
                                                        q.count +
                                                        ' queries in this group'"
                                                        @click.stop="toggleGroup(q.key)"
                                                    >
                                                        <svg
                                                            class="size-3.5 transition-transform duration-200"
                                                            :class="openGroups.includes(q.key) && 'rotate-90'"
                                                            viewBox="0 0 16 16"
                                                            fill="none"
                                                            stroke="currentColor"
                                                            stroke-width="2"
                                                            stroke-linecap="round"
                                                            stroke-linejoin="round"
                                                            aria-hidden="true"
                                                        >
                                                            <path d="M6 3.5 10.5 8 6 12.5" />
                                                        </svg>
                                                    </button>
                                                    <div class="min-w-0">
                                                        <div
                                                            class="font-mono text-xs font-medium [overflow-wrap:anywhere]"
                                                            x-show="groupBy === 'table' || groupBy === 'transaction'"
                                                            x-text="
                                                                q.key === '(none)' ? 'outside a transaction' : q.key
                                                            "
                                                        ></div>
                                                        <span class="flex items-start gap-1.5">
                                                            <code
                                                                class="block max-w-[680px] font-mono text-xs [overflow-wrap:anywhere] whitespace-pre-wrap"
                                                                :class="(groupBy === 'table' ||
                                                                    groupBy === 'transaction') &&
                                                                'mt-0.5 text-muted'"
                                                                x-text="
                                                                    groupBy === 'shape' ? q.key : (q.example ?? q.key)
                                                                "
                                                            ></code>
                                                            <span
                                                                x-html="
                                                                    copyButton(
                                                                        groupBy === 'shape'
                                                                            ? q.key
                                                                            : (q.example ?? q.key),
                                                                        'query',
                                                                        {
                                                                            hover: true,
                                                                        },
                                                                    )
                                                                "
                                                            ></span>
                                                        </span>
                                                        <span x-html="originHtml(q.origin)"></span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td :class="cls.td" class="text-right tabular-nums" x-text="q.count"></td>
                                            <td
                                                :class="cls.td"
                                                class="text-right tabular-nums"
                                                x-text="ms(q.total_ms)"
                                            ></td>
                                            <td
                                                :class="cls.td"
                                                class="text-right tabular-nums"
                                                x-text="ms(q.avg_ms)"
                                            ></td>
                                            <td
                                                :class="cls.td"
                                                class="text-right tabular-nums"
                                                x-text="ms(q.max_ms)"
                                            ></td>
                                        </tr>
                                        <tr x-show="openGroups.includes(q.key)" x-cloak>
                                            <td colspan="5" class="border-line bg-sunk/50 border-b p-0">
                                                <div x-show="openGroups.includes(q.key)" x-collapse>
                                                    <template x-for="n in q.rows ?? []" :key="n">
                                                        <div class="border-line border-b last:border-b-0">
                                                            <div
                                                                class="hover:bg-sunk group/copy grid cursor-pointer grid-cols-[3.5rem_minmax(0,1fr)_auto] gap-x-3 px-3 py-1.5 text-xs"
                                                                @click="toggleQuery(n)"
                                                            >
                                                                <button
                                                                    type="button"
                                                                    class="text-muted inline-flex cursor-pointer items-start gap-1 font-mono tabular-nums"
                                                                    :aria-expanded="openQueries.includes(n)"
                                                                    :aria-label="(openQueries.includes(n)
                                                                        ? 'Hide'
                                                                        : 'Show') +
                                                                    ' the details of query ' +
                                                                    n"
                                                                    @click.stop="toggleQuery(n)"
                                                                >
                                                                    <svg
                                                                        class="text-brass mt-0.5 size-3 transition-transform duration-200"
                                                                        :class="openQueries.includes(n) && 'rotate-90'"
                                                                        viewBox="0 0 16 16"
                                                                        fill="none"
                                                                        stroke="currentColor"
                                                                        stroke-width="2"
                                                                        stroke-linecap="round"
                                                                        stroke-linejoin="round"
                                                                        aria-hidden="true"
                                                                    >
                                                                        <path d="M6 3.5 10.5 8 6 12.5" />
                                                                    </svg>
                                                                    <span x-text="'#' + n"></span>
                                                                </button>
                                                                <div class="min-w-0">
                                                                    <span class="flex items-start gap-1.5">
                                                                        <code
                                                                            class="block font-mono [overflow-wrap:anywhere] whitespace-pre-wrap"
                                                                            x-text="
                                                                                queryRows[n]?.sql_with_bindings ??
                                                                                queryRows[n]?.sql ??
                                                                                '...'
                                                                            "
                                                                        ></code>
                                                                        <span
                                                                            x-html="
                                                                                copyButton(
                                                                                    queryRows[n]?.sql_with_bindings ??
                                                                                        queryRows[n]?.sql ??
                                                                                        '',
                                                                                    'query',
                                                                                    { hover: true },
                                                                                )
                                                                            "
                                                                        ></span>
                                                                    </span>
                                                                    <span x-html="cell(queryRows[n] ?? {}, 'origin')"></span>
                                                                    <span
                                                                        class="text-muted font-mono text-[11px]"
                                                                        x-text="queryRows[n]?.connection ?? ''"
                                                                    ></span>
                                                                </div>
                                                                <span
                                                                    class="text-right whitespace-nowrap tabular-nums"
                                                                    x-html="
                                                                        queryRows[n]
                                                                            ? cell(queryRows[n], 'duration')
                                                                            : ''
                                                                    "
                                                                ></span>
                                                            </div>
                                                            <div x-show="openQueries.includes(n)" x-collapse x-cloak>
                                                                <div
                                                                    class="pr-3 pb-3 pl-[5rem]"
                                                                    x-html="
                                                                        queryRows[n] ? queryDetails(queryRows[n]) : ''
                                                                    "
                                                                ></div>
                                                            </div>
                                                        </div>
                                                    </template>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </template>
                            </table>
                        </div>
                        <button
                            type="button"
                            class="mt-2.5"
                            :class="cls.link"
                            x-show="groupBy === 'order' && tabData?.next_offset != null"
                            @click="loadMore()"
                            x-text="'Show more (' + (tabData?.total - tabData?.rows?.length) + ' left)'"
                        ></button>
                    </section>

                    <section
                        id="monocle-panel-trace"
                        role="tabpanel"
                        :aria-labelledby="'monocle-tab-' + tab"
                        tabindex="0"
                        x-show="tab === 'trace'"
                    >
                        <div class="grid gap-1.5">
                            <template x-for="x in tabData?.captures ?? []" :key="x.id">
                                <div
                                    class="border-line bg-panel grid grid-cols-[minmax(0,1fr)_auto_auto] items-baseline gap-3 rounded-md border px-3 py-2"
                                    :class="x.id === selectedId
                                        ? 'border-brass shadow-[inset_3px_0_0_var(--brass)]'
                                        : ''"
                                    :style="`margin-left:${(x.depth ?? 0) * 24}px`"
                                >
                                    <span class="flex min-w-0 items-baseline gap-2 [overflow-wrap:anywhere]">
                                        <span :class="cls.type" x-text="x.type"></span>
                                        <button
                                            type="button"
                                            :class="cls.link"
                                            x-show="x.id !== selectedId"
                                            @click="select(x.id)"
                                            x-text="x.name"
                                        ></button>
                                        <span x-show="x.id === selectedId" x-text="x.name"></span>
                                    </span>
                                    <span
                                        class="font-mono text-xs"
                                        :class="statusClass(x.status)"
                                        x-text="x.status ?? ''"
                                    ></span>
                                    <span class="text-right tabular-nums">
                                        <span
                                            class="block font-mono text-xs whitespace-nowrap"
                                            x-text="ms(x.duration)"
                                        ></span>
                                        <span
                                            class="text-muted block text-[11px] whitespace-nowrap"
                                            x-show="x.offset_ms != null && x.depth > 0"
                                            x-text="'at ' + ms(x.offset_ms)"
                                        ></span>
                                    </span>
                                </div>
                            </template>
                        </div>
                    </section>

                    <section
                        id="monocle-panel-rows"
                        role="tabpanel"
                        :aria-labelledby="'monocle-tab-' + tab"
                        tabindex="0"
                        x-show="! ['overview', 'timeline', 'queries', 'trace', 'request', 'session'].includes(tab)"
                    >
                        <div
                            class="text-muted mb-2.5 flex flex-wrap items-center gap-1.5 text-xs"
                            x-show="tab === 'http'"
                        >
                            Show
                            <template
                                x-for="
                                    g in
                                    [
                                        ['order', 'in order'],
                                        ['endpoint', 'by endpoint'],
                                        ['host', 'by host'],
                                        ['pool', 'by pool'],
                                        ['status', 'by status'],
                                    ]
                                "
                                :key="g[0]"
                            >
                                <button
                                    type="button"
                                    :class="cls.chip"
                                    @click="
                                        httpGroup = g[0];
                                        openGroups = [];
                                    "
                                    :aria-pressed="httpGroup === g[0]"
                                    x-text="g[1]"
                                ></button>
                            </template>
                        </div>
                        <div
                            class="text-muted mb-2.5 flex flex-wrap items-center gap-1.5 text-xs"
                            x-show="tab === 'log' && levelCounts.length > 1"
                            role="group"
                            aria-label="Log levels"
                        >
                            Show
                            <button
                                type="button"
                                :class="cls.chip"
                                @click="logLevels = []"
                                :aria-pressed="! logLevels.length"
                            >
                                all
                            </button>
                            <template x-for="[level, count] in levelCounts" :key="level">
                                <button
                                    type="button"
                                    :class="cls.chip"
                                    class="gap-1.5"
                                    @click="toggleLevel(level)"
                                    :aria-pressed="logLevels.includes(level)"
                                >
                                    <span :class="levelClass(level)" x-text="level"></span>
                                    <span class="tabular-nums opacity-70" x-text="count"></span>
                                </button>
                            </template>
                        </div>
                        <div :class="cls.tableWrap" x-show="httpGroups.length">
                            <table class="w-full border-collapse text-[13px]">
                                <thead>
                                    <tr>
                                        <th scope="col" :class="cls.th" x-text="humanize(httpGroup)"></th>
                                        <th scope="col" :class="[cls.th, '!text-right']">Count</th>
                                        <th scope="col" :class="[cls.th, '!text-right']">Failed</th>
                                        <th scope="col" :class="[cls.th, '!text-right']">Total</th>
                                        <th scope="col" :class="[cls.th, '!text-right']">Avg</th>
                                        <th scope="col" :class="[cls.th, '!text-right']">Max</th>
                                    </tr>
                                </thead>
                                <template x-for="g in httpGroups" :key="g.key">
                                    <tbody>
                                        <tr class="hover:bg-sunk cursor-pointer" @click="toggleGroup(g.key)">
                                            <td :class="cls.td">
                                                <div class="flex items-start gap-1.5">
                                                    <button
                                                        type="button"
                                                        class="text-brass mt-0.5 shrink-0 cursor-pointer"
                                                        :aria-expanded="openGroups.includes(g.key)"
                                                        :aria-label="(openGroups.includes(g.key) ? 'Hide' : 'Show') +
                                                        ' the ' +
                                                        g.count +
                                                        ' calls in this group'"
                                                        @click.stop="toggleGroup(g.key)"
                                                    >
                                                        <svg
                                                            class="size-3.5 transition-transform duration-200"
                                                            :class="openGroups.includes(g.key) && 'rotate-90'"
                                                            viewBox="0 0 16 16"
                                                            fill="none"
                                                            stroke="currentColor"
                                                            stroke-width="2"
                                                            stroke-linecap="round"
                                                            stroke-linejoin="round"
                                                            aria-hidden="true"
                                                        >
                                                            <path d="M6 3.5 10.5 8 6 12.5" />
                                                        </svg>
                                                    </button>
                                                    <div class="min-w-0">
                                                        <span class="group/copy flex items-start gap-1.5">
                                                            <code
                                                                class="block font-mono text-xs [overflow-wrap:anywhere]"
                                                                x-text="g.key"
                                                            ></code>
                                                            <span x-html="copyButton(g.key, httpGroup, { hover: true })"></span>
                                                        </span>
                                                        <span x-html="originHtml(g.origin)"></span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td :class="cls.td" class="text-right tabular-nums" x-text="g.count"></td>
                                            <td
                                                :class="cls.td"
                                                class="text-right tabular-nums"
                                                x-html="g.failed ? `<span class='text-bad'>${g.failed}</span>` : '0'"
                                            ></td>
                                            <td
                                                :class="cls.td"
                                                class="text-right tabular-nums"
                                                x-text="ms(g.total_ms)"
                                            ></td>
                                            <td
                                                :class="cls.td"
                                                class="text-right tabular-nums"
                                                x-text="ms(g.avg_ms)"
                                            ></td>
                                            <td
                                                :class="cls.td"
                                                class="text-right tabular-nums"
                                                x-text="ms(g.max_ms)"
                                            ></td>
                                        </tr>
                                        <tr x-show="openGroups.includes(g.key)" x-cloak>
                                            <td colspan="6" class="border-line bg-sunk/50 border-b p-0">
                                                <div x-show="openGroups.includes(g.key)" x-collapse>
                                                    <template x-for="i in g.rows" :key="i">
                                                        <div class="border-line border-b last:border-b-0">
                                                            <div
                                                                class="hover:bg-sunk group/copy grid cursor-pointer grid-cols-[3.5rem_minmax(0,1fr)_auto] gap-x-3 px-3 py-1.5 text-xs"
                                                                @click="toggleRow('http:' + i)"
                                                            >
                                                                <button
                                                                    type="button"
                                                                    class="inline-flex cursor-pointer items-start gap-1 font-mono tabular-nums"
                                                                    :aria-expanded="openRows.includes('http:' + i)"
                                                                    :aria-label="(openRows.includes('http:' + i)
                                                                        ? 'Hide'
                                                                        : 'Show') + ' the details of this call'"
                                                                    @click.stop="toggleRow('http:' + i)"
                                                                >
                                                                    <svg
                                                                        class="text-brass size-3 transition-transform duration-200"
                                                                        :class="openRows.includes('http:' + i) &&
                                                                        'rotate-90'"
                                                                        viewBox="0 0 16 16"
                                                                        fill="none"
                                                                        stroke="currentColor"
                                                                        stroke-width="2"
                                                                        stroke-linecap="round"
                                                                        stroke-linejoin="round"
                                                                        aria-hidden="true"
                                                                    >
                                                                        <path d="M6 3.5 10.5 8 6 12.5" />
                                                                    </svg>
                                                                    <span x-html="cell(tabData.rows[i], 'status')"></span>
                                                                </button>
                                                                <div class="min-w-0">
                                                                    <code
                                                                        class="block font-mono [overflow-wrap:anywhere]"
                                                                        x-text="
                                                                            tabData.rows[i].method +
                                                                            ' ' +
                                                                            tabData.rows[i].url
                                                                        "
                                                                    ></code>
                                                                    <span x-html="cell(tabData.rows[i], 'origin')"></span>
                                                                </div>
                                                                <span
                                                                    class="text-right whitespace-nowrap tabular-nums"
                                                                    x-html="cell(tabData.rows[i], 'duration')"
                                                                ></span>
                                                            </div>
                                                            <div
                                                                x-show="openRows.includes('http:' + i)"
                                                                x-collapse
                                                                x-cloak
                                                            >
                                                                <div
                                                                    class="grid max-w-[1200px] gap-2.5 pr-3 pb-3 pl-[5rem] text-[13px]"
                                                                    x-html="
                                                                        openRows.includes('http:' + i)
                                                                            ? details(tabData.rows[i], 'http')
                                                                            : ''
                                                                    "
                                                                ></div>
                                                            </div>
                                                        </div>
                                                    </template>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </template>
                            </table>
                        </div>
                        <template x-for="t in httpGroups.length ? [] : tables" :key="t.key">
                            <div class="mb-5 last:mb-0" x-show="t.rows.length">
                                <h3
                                    class="text-muted mb-2 text-xs font-semibold tracking-wide uppercase"
                                    x-show="t.title"
                                    x-text="t.title"
                                ></h3>
                                <div :class="cls.tableWrap">
                                    <table class="w-full border-collapse text-[13px]">
                                        <thead>
                                            <tr>
                                                <th
                                                    scope="col"
                                                    :class="cls.th"
                                                    class="w-0 !text-center whitespace-nowrap"
                                                >
                                                    #
                                                </th>
                                                <template x-for="col in columnsOf(t.rows, t.key)" :key="col"
                                                    ><th
                                                        scope="col"
                                                        :class="[
                                                            cls.th,
                                                            numeric(col) && '!text-right',
                                                            narrow(col) && 'w-0 whitespace-nowrap',
                                                        ]"
                                                        x-text="columnLabel(col)"
                                                    ></th
                                                ></template>
                                            </tr>
                                        </thead>
                                        <template x-for="(row, i) in t.rows" :key="i">
                                            <tbody>
                                                <tr
                                                    class="hover:bg-sunk group/copy cursor-pointer"
                                                    :class="row.repeat_of || row.duplicate_of ? 'bg-warn-soft/60' : ''"
                                                    @click="toggleRow(t.key + ':' + i)"
                                                >
                                                    <td :class="cls.td" class="w-0 whitespace-nowrap">
                                                        <button
                                                            type="button"
                                                            class="text-muted inline-flex cursor-pointer items-center gap-1 font-mono text-xs tabular-nums"
                                                            :aria-expanded="openRows.includes(t.key + ':' + i)"
                                                            :aria-label="(openRows.includes(t.key + ':' + i)
                                                                ? 'Hide'
                                                                : 'Show') +
                                                            ' the details of row ' +
                                                            (i + 1)"
                                                            @click.stop="toggleRow(t.key + ':' + i)"
                                                        >
                                                            <svg
                                                                class="text-brass size-3 transition-transform duration-200"
                                                                :class="openRows.includes(t.key + ':' + i) &&
                                                                'rotate-90'"
                                                                viewBox="0 0 16 16"
                                                                fill="none"
                                                                stroke="currentColor"
                                                                stroke-width="2"
                                                                stroke-linecap="round"
                                                                stroke-linejoin="round"
                                                                aria-hidden="true"
                                                            >
                                                                <path d="M6 3.5 10.5 8 6 12.5" />
                                                            </svg>
                                                            <span x-text="row.n ?? i + 1"></span>
                                                        </button>
                                                    </td>
                                                    <template x-for="col in columnsOf(t.rows, t.key)" :key="col"
                                                        ><td
                                                            :class="[
                                                                cls.td,
                                                                numeric(col) && '!text-right tabular-nums',
                                                                ! longValue(row[col]) && '![overflow-wrap:break-word]',
                                                                col === 'origin' && 'min-w-56',
                                                                ['model', 'action', 'key', 'type'].includes(col) &&
                                                                    'whitespace-nowrap',
                                                                narrow(col) && 'w-0 whitespace-nowrap',
                                                            ]"
                                                            x-html="cell(row, col)"
                                                        ></td
                                                    ></template>
                                                </tr>
                                                <tr x-show="openRows.includes(t.key + ':' + i)" x-cloak>
                                                    <td
                                                        :colspan="columnsOf(t.rows, t.key).length + 1"
                                                        class="border-line bg-sunk/50 border-b px-3 py-0"
                                                    >
                                                        <div x-show="openRows.includes(t.key + ':' + i)" x-collapse>
                                                            <div
                                                                class="grid max-w-[1200px] gap-2.5 py-3 pl-5 text-[13px]"
                                                                x-html="
                                                                    openRows.includes(t.key + ':' + i)
                                                                        ? details(row, t.key)
                                                                        : ''
                                                                "
                                                            ></div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </template>
                                    </table>
                                </div>
                            </div>
                        </template>
                        <dl class="m-0 grid gap-1.5" x-show="tabData?.data">
                            <template x-for="[k, v] in Object.entries(tabData?.data ?? {})" :key="k">
                                <div class="border-line grid grid-cols-[minmax(120px,220px)_minmax(0,1fr)] gap-3 border-b py-1.5">
                                    <dt class="text-muted text-[12.5px]" x-text="k"></dt>
                                    <dd class="group/copy m-0 font-mono text-xs [overflow-wrap:anywhere]">
                                        <span x-text="typeof v === 'object' ? JSON.stringify(v) : v"></span>
                                        <span
                                            x-html="
                                                copyButton(typeof v === 'object' ? JSON.stringify(v) : String(v), k, {
                                                    hover: true,
                                                })
                                            "
                                        ></span>
                                    </dd>
                                </div>
                            </template>
                        </dl>
                        <button
                            type="button"
                            class="mt-2.5"
                            :class="cls.link"
                            x-show="tabData?.next_offset != null && ! httpGroups.length"
                            @click="loadMore()"
                            x-text="'Show more (' + (tabData?.total - tabData?.rows?.length) + ' left)'"
                        ></button>
                    </section>
                    <p class="text-muted text-xs" x-show="tabLoading">Loading...</p>
                </div>
            </template>
        </main>
    </div>
</body>
</html>
