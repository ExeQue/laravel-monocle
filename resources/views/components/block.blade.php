{{-- A labelled block in an expanded row, with an optional copy button next to its label. --}}
@props(['title', 'copy' => null])
<div {{ $attributes->class(['grid min-w-0 content-start gap-1.5']) }}>
    <div class="flex items-center gap-1.5">
        <div class="text-muted text-[11px] tracking-wider uppercase">{{ $title }}</div>
        @if ($copy !== null && $copy !== '')
            <x-monocle::copy :text="$copy" :what="strtolower($title)" />
        @endif
    </div>
    {{ $slot }}
</div>
