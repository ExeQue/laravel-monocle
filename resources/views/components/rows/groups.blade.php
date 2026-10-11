{{-- Rows grouped by the picked field; a group folds out to its rows, a row to its details. --}}
<div :class="cls.tableWrap" x-show="groups.length">
    <table class="w-full border-collapse text-[13px]">
        <thead>
            <tr>
                <th
                    scope="col"
                    :class="cls.th"
                    x-text="
                        (groupings.find((g) => g[0] === rowGroup) ?? [, ''])[1]
                            .replace(/^by /, '')
                            .replace(/^./, (c) => c.toUpperCase())
                    "
                ></th>
                <th scope="col" :class="[cls.th, '!text-right']">Count</th>
                <th scope="col" :class="[cls.th, '!text-right']" x-show="groups.some((g) => g.failed)">Failed</th>
                <th scope="col" :class="[cls.th, '!text-right']">Total</th>
                <th scope="col" :class="[cls.th, '!text-right']">Avg</th>
                <th scope="col" :class="[cls.th, '!text-right']">Max</th>
            </tr>
        </thead>
        <template x-for="g in groups" :key="g.key">
            <tbody>
                <tr class="hover:bg-sunk cursor-pointer" @click="toggleGroup(g.key)">
                    <td :class="cls.td">
                        <div class="flex items-start gap-1.5">
                            <x-monocle::expand-button
                                open="openGroups.includes(g.key)"
                                toggle="toggleGroup(g.key)"
                                label="'the ' + g.count + ' rows in this group'"
                                chevron="size-3.5"
                                class="text-brass mt-0.5 shrink-0"
                            ></x-monocle::expand-button>
                            <div class="min-w-0">
                                <span class="group/copy flex items-start gap-1.5">
                                    <code
                                        class="block font-mono text-xs [overflow-wrap:anywhere]"
                                        x-text="g.key"
                                    ></code>
                                    <span x-html="copyButton(g.key, rowGroup, { hover: true })"></span>
                                </span>
                                <span x-html="originHtml(g.origin)"></span>
                            </div>
                        </div>
                    </td>
                    <td :class="cls.td" class="text-right tabular-nums" x-text="g.count"></td>
                    <td
                        :class="cls.td"
                        class="text-right tabular-nums"
                        x-show="groups.some((group) => group.failed)"
                        x-html="g.failed ? `<span class='text-bad'>${g.failed}</span>` : '0'"
                    ></td>
                    <td :class="cls.td" class="text-right tabular-nums" x-text="ms(g.total_ms)"></td>
                    <td :class="cls.td" class="text-right tabular-nums" x-text="ms(g.avg_ms)"></td>
                    <td :class="cls.td" class="text-right tabular-nums" x-text="ms(g.max_ms)"></td>
                </tr>
                <tr x-show="openGroups.includes(g.key)" x-cloak>
                    <td colspan="6" class="border-line bg-sunk/50 border-b p-0">
                        <div x-show="openGroups.includes(g.key)" x-collapse>
                            <template x-for="i in g.rows" :key="i">
                                <div class="border-line border-b last:border-b-0">
                                    <div
                                        class="hover:bg-sunk group/copy grid cursor-pointer grid-cols-[3.5rem_minmax(0,1fr)_auto] gap-x-3 px-3 py-1.5 text-xs"
                                        @click="toggleRow(tab + ':' + i)"
                                    >
                                        <x-monocle::expand-button
                                            open="openRows.includes(tab + ':' + i)"
                                            toggle="toggleRow(tab + ':' + i)"
                                            label="'the details of this row'"
                                            chevron="size-3"
                                            class=""
                                        >
                                            <span
                                                x-html="
                                                    tab === 'http'
                                                        ? cell(tabData.rows[i], 'status')
                                                        : '#' + (tabData.rows[i].n ?? i + 1)
                                                "
                                            ></span>
                                        </x-monocle::expand-button>
                                        <div class="min-w-0">
                                            <code
                                                class="block font-mono [overflow-wrap:anywhere]"
                                                x-html="classes(memberLabel(tabData.rows[i]))"
                                            ></code>
                                            <span x-html="cell(tabData.rows[i], 'origin')"></span>
                                        </div>
                                        <span
                                            class="text-right whitespace-nowrap tabular-nums"
                                            x-html="cell(tabData.rows[i], 'duration')"
                                        ></span>
                                    </div>
                                    <x-monocle::collapse
                                        open="openRows.includes(tab + ':' + i)"
                                        html="details(tabData.rows[i], tab)"
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
