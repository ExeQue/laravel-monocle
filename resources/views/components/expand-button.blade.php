{{--
    The button that folds a row out: a chevron followed by the slot (a row number, a status).
    `open` and `toggle` are Alpine expressions, `label` an Alpine expression naming what opens.
--}}
@props(['open', 'toggle', 'label', 'chevron' => 'size-3'])
<button
    type="button"
    {{ $attributes->class(['inline-flex cursor-pointer items-start gap-1 font-mono tabular-nums']) }}
    :aria-expanded="{{ $open }}"
    :aria-label="(({{ $open }}) ? 'Hide ' : 'Show ') + ({{ $label }})"
    @click.stop="{{ $toggle }}"
>
    <x-monocle::chevron :open="$open" :size="$chevron" class="mt-0.5" />
    {{ $slot }}
</button>
