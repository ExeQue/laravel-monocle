{{-- The capture with the captures it dispatched, as a tree. --}}
<x-monocle::panel name="trace">
    <div class="grid gap-1.5">
        <template x-for="x in tabData?.captures ?? []" :key="x.id">
            <div
                class="border-line bg-panel grid grid-cols-[minmax(0,1fr)_auto_auto] items-baseline gap-3 rounded-md border px-3 py-2"
                :class="x.id === selectedId ? 'border-brass shadow-[inset_3px_0_0_var(--brass)]' : ''"
                :style="`margin-left:${(x.depth ?? 0) * 24}px`"
            >
                <span class="flex min-w-0 items-baseline gap-2 [overflow-wrap:anywhere]">
                    <span :class="cls.type" x-text="x.type"></span>
                    <button
                        type="button"
                        :class="cls.link"
                        x-show="x.id !== selectedId"
                        @click="select(x.id)"
                        x-text="x.name"
                    ></button>
                    <span x-show="x.id === selectedId" x-text="x.name"></span>
                </span>
                <span class="font-mono text-xs" :class="statusClass(x.status)" x-text="x.status ?? ''"></span>
                <span class="text-right tabular-nums">
                    <span class="block font-mono text-xs whitespace-nowrap" x-text="ms(x.duration)"></span>
                    <span
                        class="text-muted block text-[11px] whitespace-nowrap"
                        x-show="x.offset_ms != null && x.depth > 0"
                        x-text="'at ' + ms(x.offset_ms)"
                    ></span>
                </span>
            </div>
        </template>
    </div>
</x-monocle::panel>
