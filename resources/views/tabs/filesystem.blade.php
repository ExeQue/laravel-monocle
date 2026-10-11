{{-- Storage operations per disk, groupable by path, operation or disk. --}}
<x-monocle::panel name="filesystem">
    <template x-if="tab === 'filesystem'">
        <div>
            <x-monocle::section-table />
        </div>
    </template>
</x-monocle::panel>
