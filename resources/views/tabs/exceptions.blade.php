{{-- Reported exceptions; a row folds out to its chain and stack. --}}
<x-monocle::panel name="exceptions">
    <template x-if="tab === 'exceptions'">
        <div>
            <x-monocle::section-table />
        </div>
    </template>
</x-monocle::panel>
