{{-- Log entries, filterable by level. --}}
<x-monocle::panel name="log">
    <template x-if="tab === 'log'">
        <div>
            <x-monocle::chips
                label="Log levels"
                options="[['', 'all'], ...levelCounts.map(([level, count]) => [level, level, count])]"
                active="option[0] === '' ? ! logLevels.length : logLevels.includes(option[0])"
                pick="option[0] === '' ? (logLevels = []) : toggleLevel(option[0])"
                x-show="levelCounts.length > 1"
            >
                <span :class="levelClass(option[0])" x-text="option[1]"></span>
                <span class="tabular-nums opacity-70" x-show="option[2]" x-text="option[2]"></span>
            </x-monocle::chips>
            <x-monocle::section-table />
        </div>
    </template>
</x-monocle::panel>
