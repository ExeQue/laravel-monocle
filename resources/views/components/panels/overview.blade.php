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
            <div class="mt-3 grid grid-cols-[repeat(auto-fill,minmax(150px,1fr))] gap-x-4 gap-y-2">
                <template x-for="p in phases" :key="p.name">
                    <div class="grid grid-cols-[10px_minmax(0,1fr)_auto] items-baseline gap-x-2 text-[13px]">
                        <i class="size-2.5 self-center rounded-sm" :style="`background:${phaseColor(p.name)}`"></i>
                        <span><span x-text="p.name"></span>
                            <span class="text-muted text-xs" x-show="p.count > 1" x-text="'×' + p.count"></span
                        ></span>
                        <span class="font-mono text-xs tabular-nums" x-text="ms(p.ms)"></span>
                        <span class="text-muted col-span-2 col-start-2 -mt-1 text-xs" x-text="p.percent + ' %'"></span>
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
                        <span class="[overflow-wrap:anywhere]" x-html="linkText(w, capture.warning_links?.[wi])"></span>
                    </div>
                </template>
            </div>
            <p :class="cls.empty" x-show="! summary.warnings?.length">No warnings.</p>
        </div>
        <div>
            <h3 :class="cls.sectionTitle">Totals</h3>
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
                <dl class="border-line m-0 grid grid-cols-[auto_repeat(3,auto_minmax(0,1fr))] gap-x-1.5 border-t pt-3 text-[13px]">
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
