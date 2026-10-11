{{-- A titled block; `show` is an optional Alpine expression deciding whether it shows. --}}
@props(['title', 'show' => null])
<div {{ $attributes }} @if ($show) x-show="{{ $show }}" @endif>
    <h3 :class="cls.sectionTitle">{{ $title }}</h3>
    {{ $slot }}
</div>
