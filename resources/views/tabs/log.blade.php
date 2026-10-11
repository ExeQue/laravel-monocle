{{-- Log entries, filterable by level. --}}
<x-monocle::panel
    name="log"
    x-data="{
        levels: [],
        {{-- Each level present, most severe first, with its count. --}}
        get levelCounts() {
            const order = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug']
            const counts = {}
            ;(tabData?.rows ?? []).forEach((row) => (counts[row.level] = (counts[row.level] ?? 0) + 1))
            return Object.entries(counts).sort(([a], [b]) => order.indexOf(a) - order.indexOf(b))
        },
        toggleLevel(level) {
            this.levels = this.levels.includes(level) ? this.levels.filter((l) => l !== level) : [...this.levels, level]
        },
        {{-- The table shows only the picked levels. --}}
        get tables() {
            if (groups.length) return []
            const rows = tabData?.rows ?? []
            return [{ key: 'log', title: null, rows: this.levels.length ? rows.filter((row) => this.levels.includes(row.level)) : rows }]
        },
    }"
    x-init="$watch('selectedId', () => (levels = []))"
>
    <template x-if="tab === 'log'">
        <div>
            <x-monocle::chips
                label="Log levels"
                options="[['', 'all'], ...levelCounts.map(([level, count]) => [level, level, count])]"
                active="option[0] === '' ? ! levels.length : levels.includes(option[0])"
                pick="option[0] === '' ? (levels = []) : toggleLevel(option[0])"
                x-show="levelCounts.length > 1"
            >
                <span :class="levelClass(option[0])" x-text="option[1]"></span>
                <span class="tabular-nums opacity-70" x-show="option[2]" x-text="option[2]"></span>
            </x-monocle::chips>
            <x-monocle::section-table />
        </div>
    </template>
</x-monocle::panel>
