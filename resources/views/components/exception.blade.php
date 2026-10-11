{{-- A recorded exception and the ones it wraps: full class name above the message, wrapped ones indented, each copyable. --}}
@props(['exception'])
@php($chain = [$exception, ...($exception['previous'] ?? [])])
<div class="border-line bg-panel grid gap-2 rounded-md border p-2.5 text-xs">
    @foreach ($chain as $i => $thrown)
        <div
            class="{{ $i ? 'border-line border-l-2 pl-3' : '' }} grid gap-0.5"
            style="margin-left: {{ min($i, 4) * 12 }}px"
        >
            <span class="flex items-start gap-1.5">
                <x-monocle::name
                    :value="$thrown['class']"
                    :class="'font-mono [overflow-wrap:anywhere] '.($i ? 'text-muted' : 'text-bad')"
                    full
                />
                <x-monocle::copy
                    :text="$thrown['class'].': '.($thrown['message'] ?? '')"
                    :what="$i ? 'wrapped exception '.$i : 'exception'"
                />
            </span>
            <span class="[overflow-wrap:anywhere]">{{ $thrown['message'] ?? '' }}</span>
            @if (! empty($thrown['file']))
                <span class="text-muted font-mono text-[11px]"><x-monocle::name :value="$thrown['file']" /></span>
            @endif
        </div>
    @endforeach
</div>
