{{-- The command palette (Ctrl/⌘ K): quick actions, the open capture's tabs and the captures in the list, grouped and searchable. --}}
<div
    class="fixed inset-0 z-50 flex items-start justify-center bg-black/40 px-4 pt-[12vh]"
    x-show="palette.open"
    x-transition.opacity.duration.100ms
    @click.self="closePalette()"
>
    <div
        class="border-line bg-panel grid w-full max-w-xl overflow-hidden rounded-lg border shadow-[0_16px_40px_rgba(0,0,0,.25)]"
        role="dialog"
        aria-modal="true"
        aria-label="Go to a tab or capture"
    >
        <input
            id="monocle-palette-input"
            type="text"
            class="border-line text-fg placeholder:text-muted w-full border-b bg-transparent px-4 py-3 text-sm outline-none"
            placeholder="Go to a tab or capture"
            autocomplete="off"
            role="combobox"
            aria-controls="monocle-palette-list"
            aria-expanded="true"
            :aria-activedescendant="'monocle-palette-' + palette.index"
            x-model="palette.query"
            @keydown="paletteKey($event)"
        />
        <ul
            id="monocle-palette-list"
            class="scroll-quiet m-0 max-h-[50vh] list-none overflow-y-auto p-1.5"
            role="listbox"
        >
            <template x-for="(item, i) in paletteItems" :key="item.id">
                <li role="none">
                    <div
                        class="text-muted px-3 pt-2.5 pb-1 text-[11px]"
                        :class="item.groupIsName ? 'font-mono' : 'tracking-wider uppercase'"
                        role="presentation"
                        x-show="i === 0 || paletteItems[i - 1].group !== item.group"
                        x-text="item.group"
                    ></div>
                    <div
                        :id="'monocle-palette-' + i"
                        class="flex cursor-pointer items-center gap-3 rounded-md px-3 py-2 text-sm"
                        :class="[i === palette.index ? 'bg-brass-soft' : '', item.danger ? 'text-bad' : 'text-fg']"
                        role="option"
                        :aria-selected="i === palette.index"
                        @mousemove="palette.index = i"
                        @click="runPalette(item)"
                    >
                        <span class="flex min-w-0 flex-1 items-center gap-2">
                            <span class="truncate" x-text="item.label"></span>
                            <span
                                class="border-line text-muted shrink-0 rounded-full border px-2 font-mono text-[11px]"
                                x-show="item.route"
                                x-text="item.route"
                            ></span>
                        </span>
                        <span
                            class="shrink-0 font-mono text-xs"
                            :class="statusClass(item.status)"
                            x-text="item.status ?? ''"
                        ></span>
                        <span
                            class="text-muted max-w-[45%] shrink-0 truncate text-xs tabular-nums"
                            x-text="item.hint"
                        ></span>
                        <span
                            class="text-muted w-[3.4em] shrink-0 text-right font-mono text-[11px] uppercase"
                            x-text="item.kind"
                        ></span>
                    </div>
                </li>
            </template>
            <li class="text-muted px-3 py-2 text-sm" x-show="! paletteItems.length">Nothing matches.</li>
        </ul>
        <div class="border-line text-muted flex flex-wrap items-center gap-x-4 gap-y-1.5 border-t px-4 py-2 text-xs">
            @foreach ([['#', '#req', 'kind'], ['@', '@users', 'route'], ['$', '$live', 'section'], ['|', 'a | b', 'or']] as [$prefix, $example, $meaning])
                <button
                    type="button"
                    class="hover:text-fg cursor-pointer"
                    @click="palette.query = (palette.query.trim() ? palette.query.trim() + ' ' : '') + '{{ $prefix }}' + ('{{ $prefix }}' === '|' ? ' ' : ''); $nextTick(() => document.getElementById('monocle-palette-input')?.focus())"
                >
                    <kbd class="border-line text-fg rounded border px-1 font-mono">{{ $example }}</kbd>
                    {{ $meaning }}
                </button>
            @endforeach
            <span class="ml-auto flex items-center gap-3">
                <span><kbd class="font-mono">↑↓</kbd> move</span>
                <span><kbd class="font-mono">↵</kbd> open</span>
                <span><kbd class="font-mono">esc</kbd> close</span>
            </span>
        </div>
    </div>
</div>
