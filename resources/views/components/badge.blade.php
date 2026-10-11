{{-- A rounded count; `count` is an Alpine expression, the classes set its colour. --}}
@props(['count'])
<span
    {{ $attributes->class(['min-w-5 rounded-full px-1.5 py-px text-center font-mono text-[10.5px] leading-4 tracking-normal normal-case tabular-nums']) }}
    x-text="{{ $count }}"
></span>
