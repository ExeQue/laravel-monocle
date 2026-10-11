{{-- One tool call an agent made: the tool, the arguments it got, its result or exception and its facts. --}}
@php
    use ExeQue\Monocle\Dashboard\Format;

    $status = $row['status'] ?? null;
    $chain = isset($row['exception']) ? implode("\n", array_map(fn (array $e): string => $e['class'].': '.($e['message'] ?? ''), [$row['exception'], ...($row['exception']['previous'] ?? [])])) : null;
@endphp
<div class="grid gap-4">
    <div class="flex flex-wrap items-start gap-1.5 font-mono text-xs">
        <b>{{ $row['tool'] ?? '' }}</b>
        <x-monocle::name :value="$row['class'] ?? ''" class="text-muted [overflow-wrap:anywhere]" full />
    </div>

    <x-monocle::facts
        :items="[
            ['Status', $status, $status === 'ok' ? 'text-ok' : 'text-bad'],
            ['Sub-agent', ! empty($row['sub_agent']) ? 'yes' : null],
            ['Agent', isset($row['agent']) ? class_basename($row['agent']) : null],
            ['Duration', isset($row['duration']) ? Format::ms($row['duration']) : null],
            ['Started at', isset($row['time']) ? Format::ms($row['time']) : null],
        ]"
    />

    @isset($row['exception'])
        <x-monocle::block title="Exception" :copy="$chain">
            <x-monocle::exception :exception="$row['exception']" />
        </x-monocle::block>
    @endisset

    <div class="grid gap-4 lg:grid-cols-2">
        <x-monocle::text title="Arguments" :text="Format::pretty($row['arguments'] ?? [])" />
        @isset($row['result'])
            <x-monocle::text title="Result" :text="Format::pretty($row['result'])" />
        @else
            <x-monocle::block title="Result"><span class="text-muted text-xs">none</span></x-monocle::block>
        @endisset
    </div>

    <x-monocle::origin :origin="$row['origin'] ?? null" />
</div>
