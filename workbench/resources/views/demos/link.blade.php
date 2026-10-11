{{-- A demo as a row: GET demos are links, POST demos submit a small form. --}}
@php
    $alarms = $demo['alarms'] ?? [];
    $search = strtolower($demo['name'].' '.$demo['text'].' '.$demo['path'].' '.implode(' ', array_column($alarms, 0)));
    $hasAlarm = collect($alarms)->contains(fn (array $alarm): bool => $alarm[1] !== 'info');
@endphp
<li class="demo" data-search="{{ $search }}" data-alarm="{{ $hasAlarm ? '1' : '0' }}">
    @if ($demo['method'] === 'POST')
        <form method="POST" action="{{ $demo['path'] }}" class="demo-row">
            @csrf
            <span class="method post">POST</span>
            <button type="submit" class="demo-main">
                <span class="demo-name">{{ $demo['name'] }}</span>
                <span class="demo-text">{{ $demo['text'] }}</span>
            </button>
            @include('demos.chips', ['alarms' => $alarms])
        </form>
    @else
        <a href="{{ $demo['path'] }}" class="demo-row">
            <span class="method">GET</span>
            <span class="demo-main">
                <span class="demo-name">{{ $demo['name'] }}</span>
                <span class="demo-text">{{ $demo['text'] }}</span>
            </span>
            @include('demos.chips', ['alarms' => $alarms])
        </a>
    @endif
</li>
