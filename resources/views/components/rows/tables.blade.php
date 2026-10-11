{{-- One table per section shown in the tab, each row folding out to its details. --}}
<template x-for="t in tables" :key="t.key">
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
                        <th scope="col" :class="cls.th" class="w-0 !text-center whitespace-nowrap">#</th>
                        <template x-for="col in columnsOf(t.rows, t.key)" :key="col"
                            ><th
                                scope="col"
                                :class="[cls.th, numeric(col) && '!text-right', narrow(col) && 'w-0 whitespace-nowrap']"
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
                                        numeric(col) && '!text-right tabular-nums',
                                        ! longValue(row[col]) && '![overflow-wrap:break-word]',
                                        col === 'origin' && 'min-w-56',
                                        ['model', 'action', 'key', 'type'].includes(col) && 'whitespace-nowrap',
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
