{{--
    A section's rows as a table: grouping chips when the lens offers groupings, the rows in order or
    grouped, each folding out to its details, and more rows on demand. Reads the open tab's data;
    each section the tab includes offers its own groupings (see rows.tables).
--}}
<x-monocle::chips
    label="Group rows"
    options="[['order', 'in order'], ...groupings]"
    active="rowGroup === option[0]"
    pick="setRowGroup(option[0])"
    x-show="groupings.length"
/>
<template x-for="s in [section(tab, tabData?.rows ?? [])]" :key="s.key">
    <x-monocle::rows.groups />
</template>
<x-monocle::rows.tables />
<x-monocle::kv-table rows="Object.entries(tabData?.data ?? {})" x-show="tabData?.data" />
<button
    type="button"
    class="mt-2.5"
    :class="cls.link"
    x-show="tabData?.next_offset != null && ! groups.length"
    @click="loadMore()"
    x-text="'Show more (' + (tabData?.total - tabData?.rows?.length) + ' left)'"
></button>
