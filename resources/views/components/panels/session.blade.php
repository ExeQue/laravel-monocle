{{-- Session driver and id, what the request changed and what the session held. --}}
<x-monocle::panel name="session">
    <template x-if="session">
        <div class="grid gap-4">
            <p class="text-muted m-0 flex flex-wrap gap-x-4 gap-y-1 text-[13px]">
                <span>Driver <b class="text-fg font-mono" x-text="session.driver"></b></span>
                <span
                    >Session <b class="text-fg font-mono" x-text="session.id"></b>
                    <span x-html="copyButton(session.id ?? '', 'session id')"></span
                ></span>
                <span x-show="session.flash?.length"
                    >Flashed for the next request: <b class="text-fg font-mono" x-text="session.flash?.join(', ')"></b
                ></span>
            </p>
            <div x-show="session.changes?.length">
                <h3 :class="cls.sectionTitle">Changed by this request</h3>
                <div class="p-1" :class="cls.tableWrap" x-html="changesTable(session.changes ?? [])"></div>
            </div>
            <p class="text-muted m-0 text-[13px]" x-show="session.changes && ! session.changes.length">
                This request did not change the session.
            </p>
            <h3 :class="cls.sectionTitle" class="mb-0">Contents</h3>
            <x-monocle::kv-table
                rows="dot(session.data)"
                value="linked(v, 'data.' + k)"
                x-show="dot(session.data).length"
            />
            <p :class="cls.empty" x-show="! dot(session.data).length">The session was empty.</p>
        </div>
    </template>
</x-monocle::panel>
