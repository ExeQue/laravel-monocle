{{-- Authentication events: logins, failures, logouts and tokens. --}}
<x-monocle::panel name="auth">
    <template x-if="tab === 'auth'">
        <div>
            <x-monocle::section-table />
        </div>
    </template>
</x-monocle::panel>
