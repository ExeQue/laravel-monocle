{{-- A job capture: its class, queue, attempt, max tries and the exception it threw. --}}
<x-monocle::panel name="job">
    <template x-if="tab === 'job'">
        <div>
            <x-monocle::section-table />
        </div>
    </template>
</x-monocle::panel>
