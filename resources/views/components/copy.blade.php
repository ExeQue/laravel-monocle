{{--
    A copy button, the same as the dashboard draws in its tables. Give the text as `text`, or as an
    Alpine expression in `bind`; name what is copied in `what` (or an Alpine expression in `what-bind`).
--}}
@props(['text' => null, 'bind' => null, 'what' => 'value', 'whatBind' => null])
<button
    type="button"
    class="text-muted hover:text-brass inline-flex shrink-0 cursor-pointer rounded p-0.5 align-[-3px]"
    @if ($bind) :data-copy="{{ $bind }}" @else data-copy="{{ $text }}" @endif
    @if ($whatBind) :aria-label="'Copy ' + ({{ $whatBind }})" @else aria-label="Copy {{ $what }}" @endif
    data-tip="Copy"
>
    <svg class="size-3.5" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <rect x="5.5" y="5.5" width="8" height="8" rx="1.5" />
        <path d="M10.5 5.5V3.5A1.5 1.5 0 0 0 9 2H3.5A1.5 1.5 0 0 0 2 3.5V9a1.5 1.5 0 0 0 1.5 1.5h2" />
    </svg>
</button>
