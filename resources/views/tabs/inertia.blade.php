{{-- Inertia pages: the component, visit and partial reload above one row per prop. --}}
<x-monocle::panel name="inertia">
    <template x-if="tab === 'inertia'">
        <div class="grid gap-4">
            <template x-for="(page, i) in tabData?.included?.inertia_pages ?? []" :key="i">
                <p class="text-muted m-0 flex flex-wrap gap-x-4 gap-y-1 text-[13px]">
                    <span>Component <b class="text-fg font-mono" x-text="page.component"></b></span>
                    <span>URL <b class="text-fg font-mono" x-text="page.url"></b></span>
                    <span>Visit <b class="text-fg" x-text="page.visit"></b></span>
                    <span x-show="page.version">Version <b class="text-fg font-mono" x-text="page.version"></b></span>
                    <span x-show="page.partial?.only?.length"
                        >Partial reload of <b class="text-fg font-mono" x-text="page.partial?.only?.join(', ')"></b
                    ></span>
                    <span x-show="page.partial?.except?.length"
                        >Partial reload except
                        <b class="text-fg font-mono" x-text="page.partial?.except?.join(', ')"></b
                    ></span>
                    <span
                        >Payload
                        <b
                            class="text-fg font-mono"
                            x-text="totalValue(['payload_bytes', page.payload_bytes, 'bytes'])"
                        ></b
                    ></span>
                    <span x-show="page.encrypt_history">History encrypted</span>
                    <span x-show="page.clear_history">History cleared</span>
                </p>
            </template>
            <div>
                <x-monocle::section-table />
            </div>
        </div>
    </template>
</x-monocle::panel>
