{{-- Any other section: a table per section, groupable, each row folding out to its details. --}}
<x-monocle::panel name="rows" show="! ['overview', 'timeline', 'queries', 'trace', 'request', 'session'].includes(tab)">
    <x-monocle::chips
        label="Group rows"
        options="[['order', 'in order'], ...groupings]"
        active="rowGroup === option[0]"
        pick="setRowGroup(option[0])"
        x-show="groupings.length"
    />
    <x-monocle::chips
        label="Log levels"
        options="[['', 'all'], ...levelCounts.map(([level, count]) => [level, level, count])]"
        active="option[0] === '' ? ! logLevels.length : logLevels.includes(option[0])"
        pick="option[0] === '' ? (logLevels = []) : toggleLevel(option[0])"
        x-show="tab === 'log' && levelCounts.length > 1"
    >
        <span :class="levelClass(option[0])" x-text="option[1]"></span>
        <span class="tabular-nums opacity-70" x-show="option[2]" x-text="option[2]"></span>
    </x-monocle::chips>
    <x-monocle::rows.groups />
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
</x-monocle::panel>
