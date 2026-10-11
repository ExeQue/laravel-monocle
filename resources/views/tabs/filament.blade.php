{{-- Filament: the panel, page and resource above one row per action, notification, table and record. --}}
<x-monocle::panel name="filament">
    <template x-if="tab === 'filament'">
        <div class="grid gap-4">
            <template x-for="(context, i) in tabData?.included?.filament_context ?? []" :key="i">
                <p class="text-muted m-0 flex flex-wrap gap-x-4 gap-y-1 text-[13px]">
                    <span x-show="context.panel">Panel <b class="text-fg font-mono" x-text="context.panel"></b></span>
                    <span x-show="context.path"
                        >Path <b class="text-fg font-mono" x-text="'/' + context.path"></b
                    ></span>
                    <span x-show="context.guard">Guard <b class="text-fg font-mono" x-text="context.guard"></b></span>
                    <span x-show="context.tenant"
                        >Tenant <b class="text-fg font-mono" x-text="context.tenant"></b
                    ></span>
                    <span x-show="context.page">Page <b class="text-fg font-mono" x-text="context.page"></b></span>
                    <span x-show="context.resource"
                        >Resource <b class="text-fg font-mono" x-text="context.resource"></b
                    ></span>
                    <span x-show="context.record != null"
                        >Record <b class="text-fg font-mono" x-text="context.record"></b
                    ></span>
                </p>
            </template>
            <div>
                <x-monocle::section-table />
            </div>
        </div>
    </template>
</x-monocle::panel>
