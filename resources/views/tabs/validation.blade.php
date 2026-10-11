{{-- Validations; a row folds out to each field's rules, input and result. --}}
<x-monocle::panel name="validation">
    <template x-if="tab === 'validation'">
        <div>
            <x-monocle::section-table />
        </div>
    </template>
</x-monocle::panel>
