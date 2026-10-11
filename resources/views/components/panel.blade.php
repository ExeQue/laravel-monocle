{{-- A tab panel, shown while its tab is open (or while `show`, an Alpine expression, is true). --}}
@props(['name', 'show' => null])
<section
    id="monocle-panel-{{ $name }}"
    role="tabpanel"
    :aria-labelledby="'monocle-tab-' + tab"
    tabindex="0"
    x-show="{{ $show ?? "tab === '{$name}'" }}"
    {{ $attributes }}
>
    {{-- A panel that fills itself with x-html (the timeline) has no room for these. --}}
    @unless ($attributes->has('x-html'))
        {{-- The open tab's own totals, warnings and errors, as on the overview; drawn for the open tab only. --}}
        <template x-if="({{ $show ?? "tab === '{$name}'" }}) && (tabTotals.length || tabProblems.length)">
            <div>
                {{-- The lens's own totals, as on the overview. --}}
                <div class="mb-4 grid gap-2" x-show="tabTotals.length">
                    <h3 :class="cls.sectionTitle" class="uppercase">Totals</h3>
                    <dl class="m-0 flex flex-wrap gap-x-6 gap-y-2">
                        <template x-for="t in tabTotals" :key="t[0]">
                            <div class="flex flex-col-reverse">
                                <dt class="text-muted text-xs" x-text="totalTitle(t)"></dt>
                                <dd
                                    class="m-0 text-lg font-bold tabular-nums"
                                    :class="totalTone(t)"
                                    x-text="totalValue(t)"
                                ></dd>
                            </div>
                        </template>
                    </dl>
                </div>

                {{-- The warnings and errors of the lens, as on the overview. --}}
                <div class="mb-4 grid gap-2" x-data="{ all: false }" x-show="tabProblems.length">
                    <h3 :class="cls.sectionTitle" class="uppercase">Warnings / Errors</h3>
                    <template x-for="p in tabProblems.slice(0, all ? undefined : 3)" :key="p.text">
                        <div
                            class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-2.5 rounded-md px-3 py-2.5"
                            :class="p.error ? 'bg-bad-soft' : 'bg-warn-soft'"
                        >
                            <span class="pt-0.5 font-mono text-[11px]" :class="p.error ? 'text-bad' : 'text-warn'"
                                >!</span>
                            <span class="[overflow-wrap:anywhere]" x-html="linkText(p.text, p.links)"></span>
                        </div>
                    </template>
                    <button
                        type="button"
                        class="text-brass cursor-pointer justify-self-start text-xs hover:underline"
                        x-show="tabProblems.length > 3"
                        @click="all = ! all"
                        x-text="all ? 'Show less' : 'Show ' + (tabProblems.length - 3) + ' more'"
                    ></button>
                </div>
            </div>
        </template>
    @endunless

    {{ $slot }}
</section>
