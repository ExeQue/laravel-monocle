{{--
    A section title that folds its section: `open` names the Alpine property it toggles,
    `count` an optional Alpine expression shown as a badge, `badge` the badge's classes.
--}}
@props(['open', 'count' => null, 'badge' => "'bg-sunk text-muted'"])
<h3 :class="cls.sectionTitle">
    <button
        type="button"
        class="inline-flex cursor-pointer items-center gap-1.5 uppercase"
        :aria-expanded="{{ $open }}"
        @click="{{ $open }} = ! {{ $open }}"
    >
        <x-monocle::chevron :open="$open" />
        {{ $slot }}
        @if ($count)
            <span
                class="min-w-5 rounded-full px-1.5 py-px text-center font-mono text-[10.5px] leading-4 tracking-normal normal-case tabular-nums"
                :class="{{ $badge }}"
                x-show="{{ $count }}"
                x-text="{{ $count }}"
            ></span>
        @endif
    </button>
</h3>
