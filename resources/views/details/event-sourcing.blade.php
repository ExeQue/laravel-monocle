{{-- One stored event: its facts, the projectors and reactors that handled it, and its properties. --}}
@php
    use ExeQue\Monocle\Dashboard\Format;

    $th = 'border-line border-b px-2 py-1 font-medium';
    $td = 'border-line border-b px-2 py-1 align-top';
    $handlers = $row['handlers'] ?? [];
    $properties = $row['properties'] ?? [];
@endphp
<div class="grid gap-4">
    <div class="flex flex-wrap items-start gap-1.5 font-mono text-xs">
        <x-monocle::name :value="$row['event'] ?? ''" full />
    </div>

    <x-monocle::facts
        :items="[
            ['Stored event', $row['stored_event_id'] ?? null],
            ['Aggregate', $row['aggregate_uuid'] ?? null],
            ['Version', $row['aggregate_version'] ?? null],
            ['Stored here', ! empty($row['stored']) ? 'yes' : 'no'],
            ['Queued handlers', ! empty($row['queued']) ? 'yes' : 'no'],
            ['In handlers', isset($row['duration']) ? Format::ms($row['duration']) : null],
            ['Started at', isset($row['time']) ? Format::ms($row['time']) : null],
        ]"
    />

    @if ($handlers !== [])
        <x-monocle::block title="Handlers ({{ count($handlers) }})">
            <table class="w-full border-collapse text-xs">
                <thead>
                    <tr class="text-muted text-left text-[11px] tracking-wider uppercase">
                        <th class="{{ $th }}">Handler</th>
                        <th class="{{ $th }}">Type</th>
                        <th class="{{ $th }}">Mode</th>
                        <th class="{{ $th }}">Status</th>
                        <th class="{{ $th }} text-right">Duration</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($handlers as $handler)
                        <tr>
                            <td class="{{ $td }} font-mono [overflow-wrap:anywhere]">
                                <x-monocle::name :value="($handler['handler'] ?? '').'@handle'" full />
                            </td>
                            <td class="{{ $td }} w-0 whitespace-nowrap">{{ $handler['type'] ?? '' }}</td>
                            <td class="{{ $td }} w-0 whitespace-nowrap">{{ $handler['mode'] ?? '' }}</td>
                            <td @class([$td, 'w-0 whitespace-nowrap', ($handler['status'] ?? null) === 'ok' ? 'text-ok' : 'text-bad'])>
                                {{ $handler['status'] ?? '' }}
                            </td>
                            <td class="{{ $td }} w-0 text-right whitespace-nowrap tabular-nums">
                                {{ isset($handler['duration']) ? Format::ms($handler['duration']) : '' }}
                            </td>
                        </tr>
                        @if (! empty($handler['exception']))
                            <tr>
                                <td colspan="5" class="{{ $td }}">
                                    <x-monocle::exception :exception="$handler['exception']" />
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </x-monocle::block>
    @endif

    @if ($properties !== [])
        <x-monocle::text
            title="Properties"
            :text="json_encode($properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)"
        />
    @endif

    <x-monocle::origin :origin="$row['origin'] ?? null" />
</div>
