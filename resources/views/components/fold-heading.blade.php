{{-- A section title that folds its section: `open` names the Alpine property it toggles. --}}
@props(['open'])
<h3 :class="cls.sectionTitle">
    <button
        type="button"
        class="inline-flex cursor-pointer items-center gap-1.5 uppercase"
        :aria-expanded="{{ $open }}"
        @click="{{ $open }} = ! {{ $open }}"
    >
        <x-monocle::chevron :open="$open" />
        {{ $slot }}
    </button>
</h3>
