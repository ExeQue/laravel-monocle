{{-- The list of captures with its filters, and the theme switch. --}}
@props(['icon'])
<aside
    class="border-line bg-panel flex min-h-0 min-w-0 shrink-0 flex-col border-b max-[860px]:max-h-[75dvh] min-[861px]:overflow-hidden min-[861px]:border-r min-[861px]:border-b-0"
    aria-label="Captures"
>
    <div class="border-line flex flex-wrap items-center gap-x-2.5 gap-y-2 border-b px-4 pt-[18px] pb-3.5">
        <img class="block shrink-0" src="{{ $icon }}" width="26" height="26" alt="" />
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
        <span
            ><span class="font-medium">Captures</span> <span class="text-muted" x-text="'(' + total + ')'"></span
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
                    ><input type="checkbox" id="monocle-errors" class="accent-brass" x-model="filters.errors" /> Only
                    errors</label>
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
                        <span :class="cls.type" class="w-[3.4em] text-center" :data-tip="c.type" :aria-label="c.type" x-text="shortType(c.type)"></span>
                        <span
                            class="overflow-hidden font-medium text-ellipsis whitespace-nowrap"
                            x-text="c.name"
                        ></span>
                        <span class="font-mono text-xs tabular-nums" x-text="ms(c.duration)"></span>
                        <span class="text-muted col-span-2 col-start-2 flex flex-wrap gap-2.5 text-xs">
                            <span class="font-mono" :class="statusClass(c.status)" x-text="c.status ?? ''"></span>
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
                                ><i class="bg-bad inline-block size-[7px] rounded-full"></i>
                                <span x-text="c.errors > 1 ? c.errors + ' errors' : 'error'"></span
                            ></span>
                            <span
                                x-show="c.label"
                                class="border-brass text-brass rounded-full border border-dashed px-2"
                                x-text="c.label"
                            ></span>
                            <template x-for="tag in c.tags ?? []" :key="tag.label">
                                <span
                                    class="rounded-full border px-2"
                                    :class="tagClass(tag.tone)"
                                    x-text="tag.label"
                                ></span>
                            </template>
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
                            ({ request: 'route', command: 'command', job: 'job', mcp: 'call' }[c.type] ?? 'these')
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
