{{--
    A class name or a path, shortened in the dashboard like every other one (basename, tooltip with
    the full name) unless `full`, and linked to the editor when Monocle can.
--}}
@props(['value', 'class' => '', 'full' => false])
@php($link = app(\ExeQue\Monocle\Dashboard\TimelinePresenter::class)->link($value))
<span
    data-monocle-name
    @if ($link) data-link="{{ $link }}" @endif
    @if ($class) data-class="{{ $class }}" @endif
    @if ($full) data-full @endif
>{{ $value }}</span>
