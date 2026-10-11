{{-- Scheduled tasks run by schedule:run. --}}
<x-monocle::panel name="scheduled_tasks">
    <template x-if="tab === 'scheduled_tasks'">
        <div>
            <x-monocle::section-table />
        </div>
    </template>
</x-monocle::panel>
