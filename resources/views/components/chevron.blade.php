{{-- A chevron that turns down while `open` (an Alpine expression) is true. --}}
@props(['open', 'size' => 'size-3'])
<svg
    {{ $attributes->class(['text-brass shrink-0 transition-transform duration-200', $size]) }}
    :class="({{ $open }}) && 'rotate-90'"
    viewBox="0 0 16 16"
    fill="none"
    stroke="currentColor"
    stroke-width="2"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
>
    <path d="M6 3.5 10.5 8 6 12.5" />
</svg>
