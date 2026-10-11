{{--
    One table per section shown in the tab, each row folding out to its details. A section the tab
    includes offers its own grouping chips and shows its rows grouped when one is picked.
--}}
<template x-for="t in tables" :key="t.key">
    <div class="mb-5 last:mb-0" x-show="t.rows.length">
        <h3
            class="text-muted mb-2 text-xs font-semibold tracking-wide uppercase"
            x-show="t.title"
            x-text="t.title"
        ></h3>
        <template x-for="s in t.key === tab ? [] : [section(t.key, t.rows)]" :key="s.key">
            <div>
                <x-monocle::chips
                    label="Group rows"
                    options="[['order', 'in order'], ...s.groupings]"
                    active="s.by === option[0]"
                    pick="setRowGroup(option[0], s.key)"
                    x-show="s.groupings.length"
                />
                <x-monocle::rows.groups />
            </div>
        </template>
        <div :class="cls.tableWrap" x-show="t.key === tab || rowGroupOf(t.key) === 'order'">
            <table class="w-full border-collapse text-[13px]">
                <thead>
                    <tr>
                        <th scope="col" :class="cls.th" class="w-0 !text-center whitespace-nowrap">#</th>
                        <template x-for="col in columnsOf(t.rows, t.key)" :key="col"
                            ><th
                                scope="col"
                                :class="[
                                    cls.th,
                                    numeric(col, t.rows) && '!text-right',
                                    narrow(col, t.rows) && 'w-0 whitespace-nowrap',
                                ]"
                                x-text="columnLabel(col, t.key)"
                            ></th
                        ></template>
                    </tr>
                </thead>
                <template x-for="(row, i) in t.rows" :key="i">
                    <tbody>
                        <tr
                            class="hover:bg-sunk cursor-pointer"
                            :class="rowTone(row, presentationOf(t.key))"
                            @click="toggleRow(t.key + ':' + i)"
                        >
                            <td :class="cls.td" class="w-0 whitespace-nowrap">
                                <x-monocle::expand-button
                                    open="openRows.includes(t.key + ':' + i)"
                                    toggle="toggleRow(t.key + ':' + i)"
                                    label="'the details of row ' + (i + 1)"
                                    chevron="size-3"
                                    class="text-muted text-xs"
                                >
                                    <span x-text="row.n ?? i + 1"></span>
                                </x-monocle::expand-button>
                            </td>
                            <template x-for="col in columnsOf(t.rows, t.key)" :key="col"
                                ><td
                                    :class="[
                                        cls.td,
                                        numeric(col, t.rows) && '!text-right tabular-nums',
                                        ! longValue(row[col]) && '![overflow-wrap:break-word] whitespace-nowrap',
                                        narrow(col, t.rows) && 'w-0 whitespace-nowrap',
                                    ]"
                                    x-html="cell(row, col, presentationOf(t.key))"
                                ></td
                            ></template>
                        </tr>
                        <tr x-show="openRows.includes(t.key + ':' + i)" x-cloak>
                            <td
                                :colspan="columnsOf(t.rows, t.key).length + 1"
                                class="border-line bg-sunk/50 border-b px-3 py-0"
                            >
                                <x-monocle::collapse
                                    open="openRows.includes(t.key + ':' + i)"
                                    html="details(row, t.key)"
                                    class="py-3 pl-5"
                                />
                            </td>
                        </tr>
                    </tbody>
                </template>
            </table>
        </div>
    </div>
</template>
