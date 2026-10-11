{{-- Feature flag checks and their values. --}}
<x-monocle::panel name="features">
    <template x-if="tab === 'features'">
        <div>
            <x-monocle::section-table />
        </div>
    </template>
</x-monocle::panel>
