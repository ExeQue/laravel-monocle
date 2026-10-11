{{-- Queries grouped by query, caller, table or transaction; a group folds out to its queries, a query to its details. --}}
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
                            <x-monocle::expand-button
                                open="openGroups.includes(q.key)"
                                toggle="toggleGroup(q.key)"
                                label="'the ' + q.count + ' queries in this group'"
                                chevron="size-3.5"
                                class="text-brass mt-0.5 shrink-0"
                            ></x-monocle::expand-button>
                            <div class="min-w-0">
                                <div
                                    class="font-mono text-xs font-medium [overflow-wrap:anywhere]"
                                    x-show="groupBy === 'table' || groupBy === 'transaction'"
                                    x-text="q.key === '(none)' ? 'outside a transaction' : q.key"
                                ></div>
                                <span class="flex items-start gap-1.5">
                                    <code
                                        class="block max-w-[680px] font-mono text-xs [overflow-wrap:anywhere] whitespace-pre-wrap"
                                        :class="(groupBy === 'table' || groupBy === 'transaction') &&
                                        'mt-0.5 text-muted'"
                                        x-text="groupBy === 'shape' ? q.key : (q.example ?? q.key)"
                                    ></code>
                                    <span
                                        x-html="
                                            copyButton(groupBy === 'shape' ? q.key : (q.example ?? q.key), 'query', {
                                                hover: true,
                                            })
                                        "
                                    ></span>
                                </span>
                                <span x-html="originHtml(q.origin)"></span>
                            </div>
                        </div>
                    </td>
                    <td :class="cls.td" class="text-right tabular-nums" x-text="q.count"></td>
                    <td :class="cls.td" class="text-right tabular-nums" x-text="ms(q.total_ms)"></td>
                    <td :class="cls.td" class="text-right tabular-nums" x-text="ms(q.avg_ms)"></td>
                    <td :class="cls.td" class="text-right tabular-nums" x-text="ms(q.max_ms)"></td>
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
                                        <x-monocle::expand-button
                                            open="openQueries.includes(n)"
                                            toggle="toggleQuery(n)"
                                            label="'the details of query ' + n"
                                            chevron="size-3"
                                            class="text-muted"
                                        >
                                            <span x-text="'#' + n"></span>
                                        </x-monocle::expand-button>
                                        <div class="min-w-0">
                                            <span class="flex items-start gap-1.5">
                                                <code
                                                    class="block font-mono [overflow-wrap:anywhere] whitespace-pre-wrap"
                                                    x-text="
                                                        queryRows[n]?.sql_with_bindings ?? queryRows[n]?.sql ?? '...'
                                                    "
                                                ></code>
                                                <span
                                                    x-html="
                                                        copyButton(
                                                            queryRows[n]?.sql_with_bindings ?? queryRows[n]?.sql ?? '',
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
                                            x-html="queryRows[n] ? cell(queryRows[n], 'duration') : ''"
                                        ></span>
                                    </div>
                                    <x-monocle::collapse
                                        open="openQueries.includes(n)"
                                        html="queryDetails(queryRows[n])"
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
