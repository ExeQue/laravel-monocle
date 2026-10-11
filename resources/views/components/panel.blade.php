{{-- A tab panel, shown while its tab is open (or while `show`, an Alpine expression, is true). --}}
@props(['name', 'show' => null])
<section
    id="monocle-panel-{{ $name }}"
    role="tabpanel"
    :aria-labelledby="'monocle-tab-' + tab"
    tabindex="0"
    x-show="{{ $show ?? "tab === '{$name}'" }}"
    {{ $attributes }}
>
    {{-- The lens's own totals, as on the overview. --}}
    <div class="mb-4 grid gap-2" x-show="tabTotals.length">
        <h3 :class="cls.sectionTitle" class="uppercase">Totals</h3>
        <dl class="m-0 flex flex-wrap gap-x-6 gap-y-2">
            <template x-for="t in tabTotals" :key="t[0]">
                <div class="flex flex-col-reverse">
                    <dt class="text-muted text-xs" x-text="totalTitle(t)"></dt>
                    <dd class="m-0 text-lg font-bold tabular-nums" :class="totalTone(t)" x-text="totalValue(t)"></dd>
                </div>
            </template>
        </dl>
    </div>

    {{ $slot }}
</section>
