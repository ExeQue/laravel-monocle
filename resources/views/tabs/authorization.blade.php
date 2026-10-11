{{-- Gate and policy checks, allowed or denied. --}}
<x-monocle::panel name="authorization">
    <template x-if="tab === 'authorization'">
        <div>
            <x-monocle::section-table />
        </div>
    </template>
</x-monocle::panel>
