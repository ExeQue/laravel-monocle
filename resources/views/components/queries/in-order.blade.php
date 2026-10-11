{{-- Queries in the order they ran, each folding out to its bindings and details. --}}
<div :class="cls.tableWrap" x-show="groupBy === 'order' && tabData?.rows?.length">
    <table class="w-full border-collapse text-[13px]">
        <thead>
            <tr>
                <th scope="col" :class="cls.th" class="w-0 !text-center whitespace-nowrap">#</th>
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
                    @click="toggle(q.n)"
                >
                    <td :class="cls.td" class="text-muted w-0 font-mono text-xs whitespace-nowrap tabular-nums">
                        <x-monocle::expand-button
                            open="open.includes(q.n)"
                            toggle="toggle(q.n)"
                            label="'the details of query ' + q.n"
                            chevron="size-3"
                            class=""
                        >
                            <span x-text="q.n"></span>
                        </x-monocle::expand-button>
                    </td>
                    <td :class="cls.td">
                        <span class="group/copy flex items-start gap-1.5">
                            <code
                                class="block max-w-[680px] font-mono text-xs [overflow-wrap:anywhere] whitespace-pre-wrap"
                                x-text="q.sql_with_bindings ?? q.sql"
                            ></code>
                            <x-monocle::copy bind="q.sql_with_bindings ?? q.sql" what="query" hover />
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
                                x-show="q.repeated"
                                x-text="'repeated ' + q.repeated + '×'"
                            ></span>
                            <span
                                class="bg-warn-soft text-warn rounded px-1.5 font-mono text-[10.5px]"
                                x-show="q.n_plus_one"
                                x-text="'N+1 ×' + q.n_plus_one"
                            ></span>
                            <span class="bg-bad-soft text-bad rounded px-1.5 font-mono text-[10.5px]" x-show="q.slow"
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
                <tr x-show="open.includes(q.n)" x-cloak>
                    <td colspan="4" class="border-line bg-sunk/50 border-b px-3 py-0">
                        <x-monocle::collapse open="open.includes(q.n)" html="details(q, 'queries')" class="py-3" />
                    </td>
                </tr>
            </tbody>
        </template>
    </table>
</div>
