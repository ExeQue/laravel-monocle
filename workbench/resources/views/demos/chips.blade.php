<span class="chips">
    @foreach ($alarms as [$label, $tone])
        <span class="chip {{ $tone }}">{{ $label }}</span>
    @endforeach
</span>
