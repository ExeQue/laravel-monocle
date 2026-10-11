{{-- Content that folds open while `open` is true; `html` is only rendered while it is open. --}}
@props(['open', 'html'])
<div x-show="{{ $open }}" x-collapse x-cloak>
    <div
        {{ $attributes->class(['grid max-w-[1200px] gap-2.5 text-[13px]']) }}
        x-html="({{ $open }}) ? {{ $html }} : ''"
    ></div>
</div>
