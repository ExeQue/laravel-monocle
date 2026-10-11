{{-- Jobs dispatched by the capture, groupable by job or queue. --}}
<x-monocle::panel name="jobs">
    <template x-if="tab === 'jobs'">
        <div>
            <x-monocle::section-table />
        </div>
    </template>
</x-monocle::panel>
