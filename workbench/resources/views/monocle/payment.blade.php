{{-- The expanded row of the Payments tab: rendered by the application, styled with the dashboard's own classes. --}}
<div class="grid gap-2 text-[13px]">
    <p class="m-0 text-base font-semibold tabular-nums">
        {{ number_format($row['amount'] / 100, 2) }} {{ $row['currency'] }}
        <span @class(['text-ok' => $row['status'] === 'paid', 'text-bad' => $row['status'] !== 'paid'])>{{ $row['status'] }}</span>
    </p>
    <p class="text-muted m-0">
        Charged to <b class="text-fg">{{ $row['customer'] }}</b> on <span class="font-mono">{{ $row['card'] }}</span>
    </p>
    @if ($row['decline_reason'])
        <p class="text-bad m-0">Declined: {{ $row['decline_reason'] }}</p>
    @endif
</div>
