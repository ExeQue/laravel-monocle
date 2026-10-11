{{-- Where a row came from: the line in the application, the call, what it went through and the call chain. --}}
@props(['origin'])
@php($origin = is_array($origin) ? app(\ExeQue\Monocle\Dashboard\TimelinePresenter::class)->origin($origin) : null)
@if ($origin)
    <div class="border-line grid gap-1 border-t pt-2">
        <div class="text-muted text-[11px] tracking-wider uppercase">Origin</div>
        <div class="text-brass font-mono text-xs break-all">
            <x-monocle::name :value="$origin['file'].(! empty($origin['line']) ? ':'.$origin['line'] : '')" />
        </div>
        @if (! empty($origin['call']))
            <div class="text-muted font-mono text-xs [overflow-wrap:anywhere]">
                <x-monocle::name :value="$origin['call']" />
            </div>
        @endif
        @if (! empty($origin['via']))
            <div class="text-muted text-xs [overflow-wrap:anywhere]">
                via
                <span class="font-mono"
                    ><x-monocle::name :value="$origin['via']['call'] ?? $origin['via']['file']"
                /></span>
            </div>
        @endif
        @if (! empty($origin['frames']))
            <details>
                <summary class="text-muted cursor-pointer text-xs">Call chain ({{ count($origin['frames']) }})</summary>
                <ol class="mt-1 grid list-decimal gap-0.5 pl-4.5 text-[11.5px] [overflow-wrap:anywhere]">
                    @foreach ($origin['frames'] as $frame)
                        <li class="font-mono">
                            <x-monocle::name :value="($frame['file'] ?? '').(! empty($frame['line']) ? ':'.$frame['line'] : '')" />
                            <x-monocle::name :value="$frame['call'] ?? ''" />
                        </li>
                    @endforeach
                </ol>
            </details>
        @endif
    </div>
@endif
