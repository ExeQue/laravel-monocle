{{-- Queries in order or grouped, each folding out to its bindings and details. --}}
<x-monocle::panel
    name="queries"
    x-data="{
        groupBy: 'order',
        groups: [],
        rows: {},
        open: [],
        toggle(n) {
            this.open = this.open.includes(n) ? this.open.filter((k) => k !== n) : [...this.open, n];
        },
        async group(by) {
            this.groupBy = by;
            this.open = [];
            if (by === 'order') return;
            const base = `${api}/${encodeURIComponent(selectedId)}`;
            const [groups, rows] = await Promise.all([
                fetchJson(`${base}/queries?group_by=${by}&limit=200`),
                fetchJson(`${base}/rows/queries?limit=500&verbose=1`),
            ]);
            this.groups = groups.rows ?? [];
            this.rows = Object.fromEntries((rows.rows ?? []).map((row) => [row.n, row]));
        },
    }"
    x-init="$watch('selectedId', () => group(groupBy))"
>
    <x-monocle::chips
        label="Group queries"
        options="[['order', 'in order'], ['shape', 'by query'], ['caller', 'by caller'], ['table', 'by table'], ['transaction', 'by transaction']]"
        active="groupBy === option[0]"
        pick="group(option[0])"
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
