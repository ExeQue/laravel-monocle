{{-- Outgoing HTTP calls; a row folds out to headers, payload, response and timing. --}}
<x-monocle::panel name="http">
    <template x-if="tab === 'http'">
        <div>
            <x-monocle::section-table />
        </div>
    </template>
</x-monocle::panel>
