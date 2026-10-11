{{--
    A section's rows grouped by the picked field; a group folds out to its rows, a row to its details.
    Reads the section `s` (see section() in dashboard.js).
--}}
<div :class="cls.tableWrap" x-show="s.groups.length">
    <table class="w-full border-collapse text-[13px]">
        <thead>
            <tr>
                <th
                    scope="col"
                    :class="cls.th"
                    x-text="
                        (s.groupings.find((g) => g[0] === s.by) ?? [, ''])[1]
                            .replace(/^by /, '')
                            .replace(/^./, (c) => c.toUpperCase())
                    "
                ></th>
                <th scope="col" :class="[cls.th, '!text-right']">Count</th>
                <th scope="col" :class="[cls.th, '!text-right']" x-show="s.groups.some((g) => g.failed)">Failed</th>
                <th scope="col" :class="[cls.th, '!text-right']" x-show="s.timed">Total</th>
                <th scope="col" :class="[cls.th, '!text-right']" x-show="s.timed">Avg</th>
                <th scope="col" :class="[cls.th, '!text-right']" x-show="s.timed">Max</th>
            </tr>
        </thead>
        <template x-for="g in s.groups" :key="g.key">
            <tbody>
                <tr class="hover:bg-sunk cursor-pointer" @click="toggleGroup(s.key + ':' + g.key)">
                    <td :class="cls.td">
                        <div class="flex items-start gap-1.5">
                            <x-monocle::expand-button
                                open="openGroups.includes(s.key + ':' + g.key)"
                                toggle="toggleGroup(s.key + ':' + g.key)"
                                label="'the ' + g.count + ' rows in this group'"
                                chevron="size-3.5"
                                class="text-brass mt-0.5 shrink-0"
                            ></x-monocle::expand-button>
                            <div class="min-w-0">
                                <span class="flex items-start gap-1.5">
                                    <code
                                        class="block font-mono text-xs [overflow-wrap:anywhere]"
                                        x-html="g.html"
                                    ></code>
                                    <x-monocle::copy bind="g.key" what-bind="s.by" />
                                </span>
                                <span x-html="originHtml(g.origin)"></span>
                            </div>
                        </div>
                    </td>
                    <td :class="cls.td" class="text-right tabular-nums" x-text="g.count"></td>
                    <td
                        :class="cls.td"
                        class="text-right tabular-nums"
                        x-show="s.groups.some((group) => group.failed)"
                        x-html="g.failed ? `<span class='text-bad'>${g.failed}</span>` : '0'"
                    ></td>
                    <td :class="cls.td" class="text-right tabular-nums" x-show="s.timed" x-text="ms(g.total_ms)"></td>
                    <td :class="cls.td" class="text-right tabular-nums" x-show="s.timed" x-text="ms(g.avg_ms)"></td>
                    <td :class="cls.td" class="text-right tabular-nums" x-show="s.timed" x-text="ms(g.max_ms)"></td>
                </tr>
                <tr x-show="openGroups.includes(s.key + ':' + g.key)" x-cloak>
                    <td colspan="6" class="border-line bg-sunk/50 border-b p-0">
                        <div x-show="openGroups.includes(s.key + ':' + g.key)" x-collapse>
                            <template x-for="i in g.rows" :key="i">
                                <div class="border-line border-b last:border-b-0">
                                    <div
                                        class="hover:bg-sunk grid cursor-pointer grid-cols-[3.5rem_minmax(0,1fr)_auto] gap-x-3 px-3 py-1.5 text-xs"
                                        @click="toggleRow(s.key + ':' + i)"
                                    >
                                        <x-monocle::expand-button
                                            open="openRows.includes(s.key + ':' + i)"
                                            toggle="toggleRow(s.key + ':' + i)"
                                            label="'the details of this row'"
                                            chevron="size-3"
                                        >
                                            <span
                                                x-html="
                                                    s.badge
                                                        ? cell(s.rows[i], s.badge, presentationOf(s.key))
                                                        : '#' + (s.rows[i].n ?? i + 1)
                                                "
                                            ></span>
                                        </x-monocle::expand-button>
                                        <div class="min-w-0">
                                            <div
                                                class="flex flex-wrap items-baseline gap-x-1.5 [overflow-wrap:anywhere]"
                                                x-html="memberLabel(s.rows[i], s)"
                                            ></div>
                                            <span x-html="cell(s.rows[i], 'origin')"></span>
                                        </div>
                                        <span
                                            class="text-right whitespace-nowrap tabular-nums"
                                            x-html="cell(s.rows[i], 'duration')"
                                        ></span>
                                    </div>
                                    <x-monocle::collapse
                                        open="openRows.includes(s.key + ':' + i)"
                                        html="details(s.rows[i], s.key)"
                                        class="pr-3 pb-3 pl-[5rem]"
                                    />
                                </div>
                            </template>
                        </div>
                    </td>
                </tr>
            </tbody>
        </template>
    </table>
</div>
