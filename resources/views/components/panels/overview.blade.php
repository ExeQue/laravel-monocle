{{-- Where the time went, what it was spent on, warnings and totals. --}}
<x-monocle::panel name="overview" class="grid grid-cols-1 gap-6 min-[1101px]:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
    <div class="grid min-w-0 content-start gap-6">
        <div x-show="phases.length">
            <h3 :class="cls.sectionTitle">Where the time went</h3>
            <div
                class="bg-sunk flex h-[26px] overflow-hidden rounded"
                role="img"
                :aria-label="'Time per phase: ' + phases.map((p) => p.name + ' ' + ms(p.ms)).join(', ')"
            >
                <template x-for="p in phases" :key="p.name">
                    <span
                        class="h-full min-w-0.5"
                        :data-tip="p.name + ': ' + ms(p.ms)"
                        :style="`width:${p.percent}%;background:${phaseColor(p.name)}`"
                    ></span>
                </template>
            </div>
            <div class="mt-3 grid grid-cols-[10px_minmax(0,1fr)_auto_auto] items-baseline gap-x-3 text-[13px]">
                <template x-for="p in phases" :key="p.name">
                    <div class="border-line col-span-full grid grid-cols-subgrid border-b border-dashed py-1 last:border-b-0">
                        <i class="size-2.5 self-center rounded-sm" :style="`background:${phaseColor(p.name)}`"></i>
                        <span class="min-w-0 truncate"><span x-text="p.name"></span>
                            <span class="text-muted text-xs" x-show="p.count > 1" x-text="'×' + p.count"></span
                        ></span>
                        <span class="text-right font-mono text-xs tabular-nums" x-text="ms(p.ms)"></span>
                        <span
                            class="text-muted w-12 text-right font-mono text-xs tabular-nums"
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
        <div x-data="{ all: false }">
            <h3 class="flex items-center gap-1.5" :class="cls.sectionTitle">
                Warnings / Errors
                <x-monocle::badge count="summary.warnings?.length ?? 0" class="bg-warn-soft text-warn" />
                <x-monocle::badge count="summary.errors?.length ?? 0" class="bg-bad-soft text-bad" />
            </h3>
            <div class="grid gap-2" x-show="problemList.length">
                <template x-for="p in problemList.slice(0, all ? undefined : 3)" :key="p.text">
                    <div
                        class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-2.5 rounded-md px-3 py-2.5"
                        :class="p.error ? 'bg-bad-soft' : 'bg-warn-soft'"
                    >
                        <span class="pt-0.5 font-mono text-[11px]" :class="p.error ? 'text-bad' : 'text-warn'">!</span>
                        <span class="[overflow-wrap:anywhere]" x-html="linkText(p.text, p.links)"></span>
                    </div>
                </template>
                <button
                    type="button"
                    class="text-brass cursor-pointer justify-self-start text-xs hover:underline"
                    x-show="problemList.length > 3"
                    @click="all = ! all"
                    x-text="all ? 'Show less' : 'Show ' + (problemList.length - 3) + ' more'"
                ></button>
            </div>
            <p :class="cls.empty" x-show="! problemList.length">No warnings or errors.</p>
        </div>
        <div x-data="{ open: true }">
            <x-monocle::fold-heading open="open">Totals</x-monocle::fold-heading>
            <div class="grid gap-4">
                <template x-for="g in totalGroups.filter((g) => g.label === 'Overall')" :key="g.label">
                    <div class="grid grid-cols-[repeat(auto-fill,minmax(120px,1fr))] gap-x-4 gap-y-3">
                        <template x-for="t in g.items" :key="t[0]">
                            <div>
                                <div class="text-muted text-xs first-letter:uppercase" x-text="totalLabel(t)"></div>
                                <div
                                    class="text-xl font-bold tabular-nums"
                                    :class="totalTone(t)"
                                    x-text="totalValue(t)"
                                ></div>
                            </div>
                        </template>
                    </div>
                </template>
                <dl
                    x-show="open"
                    x-collapse
                    class="border-line m-0 grid grid-cols-[auto_repeat(3,auto_minmax(0,1fr))] gap-x-1.5 border-t pt-3 text-[13px]"
                >
                    <template x-for="g in totalGroups.filter((g) => g.label !== 'Overall')" :key="g.label">
                        <div class="border-line col-span-full grid grid-cols-subgrid items-baseline border-b border-dashed py-1.5 last:border-b-0">
                            <dt
                                class="text-brass pr-4 text-xs font-semibold"
                                :style="`grid-row: span ${Math.ceil(g.items.length / 3)}`"
                            >
                                <button
                                    type="button"
                                    class="cursor-pointer hover:underline"
                                    x-show="g.tab"
                                    @click="openTab(g.tab)"
                                    x-text="g.label"
                                ></button>
                                <span x-show="! g.tab" x-text="g.label"></span>
                            </dt>
                            <template x-for="t in g.items" :key="t[0]">
                                <dd class="contents">
                                    <span
                                        class="text-right font-semibold whitespace-nowrap tabular-nums"
                                        :class="totalTone(t)"
                                        x-text="totalValue(t)"
                                    ></span>
                                    <span
                                        class="text-muted pr-4 text-xs whitespace-nowrap"
                                        x-text="totalLabel(t)"
                                    ></span>
                                </dd>
                            </template>
                        </div>
                    </template>
                </dl>
            </div>
        </div>
    </div>
</x-monocle::panel>
