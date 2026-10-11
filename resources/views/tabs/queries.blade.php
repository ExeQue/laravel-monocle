{{-- Queries in order or grouped, each folding out to its bindings and details. --}}
<x-monocle::panel name="queries">
    <x-monocle::chips
        label="Group queries"
        options="[['order', 'in order'], ['shape', 'by query'], ['caller', 'by caller'], ['table', 'by table'], ['transaction', 'by transaction']]"
        active="groupBy === option[0]"
        pick="groupBy = option[0]; openTab('queries')"
    />
    <x-monocle::queries.in-order />
    <x-monocle::queries.groups />
    <button
        type="button"
        class="mt-2.5"
        :class="cls.link"
        x-show="groupBy === 'order' && tabData?.next_offset != null"
        @click="loadMore()"
        x-text="'Show more (' + (tabData?.total - tabData?.rows?.length) + ' left)'"
    ></button>
</x-monocle::panel>
