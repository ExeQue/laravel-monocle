{{-- An outgoing HTTP call: the call, its facts and timing, an exception, both header sets, the payload and the response. --}}
@php
    use ExeQue\Monocle\Dashboard\Format;

    $status = $row['status'] ?? null;
    $tone = $status === null || $status >= 400 ? 'text-bad' : ($status >= 300 ? 'text-muted' : 'text-ok');
    $response = $row['response_capture'] ?? null;
    $raw = $response['raw'] ?? ($row['response_body'] ?? null);
    $type = collect(Format::headers($row['response_headers'] ?? []))->first(fn (array $h): bool => strtolower($h[0]) === 'content-type')[1] ?? '';
    $pretty = fn (?string $text): string => str_contains(strtolower((string) $type), 'json') && is_array($decoded = json_decode((string) $text, true))
        ? (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        : (string) $text;
    $chain = isset($row['exception']) ? implode("\n", array_map(fn (array $e): string => $e['class'].': '.($e['message'] ?? ''), [$row['exception'], ...($row['exception']['previous'] ?? [])])) : null;
    $phases = array_filter([
        ['dns', 'DNS', 'var(--c-cache)'], ['connect', 'Connect', 'var(--c-db)'], ['tls', 'TLS', 'var(--c-view)'],
        ['wait', 'Waiting', 'var(--c-http)'], ['download', 'Download', 'var(--c-ctrl)'], ['redirect', 'Redirects', 'var(--c-phase)'],
    ], fn (array $p): bool => is_numeric($row['timing'][$p[0]] ?? null));
    $total = array_sum(array_map(fn (array $p): float => (float) $row['timing'][$p[0]], $phases)) ?: 1;
@endphp
<div class="grid gap-4">
    <div class="flex flex-wrap items-start gap-1.5 font-mono text-xs">
        <b>{{ $row['method'] ?? '' }}</b>
        <span class="[overflow-wrap:anywhere]">{{ $row['url'] ?? '' }}</span>
        <x-monocle::copy :text="$row['url'] ?? ''" what="url" />
    </div>

    <x-monocle::facts
        :items="[
            ['Status', $status ?? 'no response', $tone],
            ['Duration', isset($row['duration']) ? Format::ms($row['duration']) : null],
            ['Started at', isset($row['time']) ? Format::ms($row['time']) : null],
            ['Sent', isset($row['request_bytes']) ? Format::bytes($row['request_bytes']) : null],
            ['Received', isset($row['response_bytes']) ? Format::bytes($row['response_bytes']) : null],
            ['Pool', isset($row['pool']) ? '#'.$row['pool'] : null],
        ]"
    />

    @if ($phases)
        <x-monocle::block title="Timing">
            <div class="grid gap-2 text-xs">
                <div
                    class="bg-sunk flex h-2.5 overflow-hidden rounded-sm"
                    role="img"
                    aria-label="{{ collect($phases)->map(fn ($p) => $p[1].' '.Format::ms($row['timing'][$p[0]]))->implode(', ') }}"
                >
                    @foreach ($phases as [$key, $label, $color])
                        <span style="width: {{ $row['timing'][$key] / $total * 100 }}%; background: {{ $color }}"></span>
                    @endforeach
                </div>
                <div class="flex flex-wrap gap-x-4 gap-y-1">
                    @foreach ($phases as [$key, $label, $color])
                        <span class="inline-flex items-center gap-1.5">
                            <i class="size-2 rounded-sm" style="background: {{ $color }}"></i>
                            <span class="text-muted">{{ $label }}</span>
                            <span class="tabular-nums">{{ Format::ms($row['timing'][$key]) }}</span>
                        </span>
                    @endforeach
                </div>
                <div class="flex flex-wrap gap-x-4 gap-y-1">
                    @foreach ([['Remote', $row['timing']['remote'] ?? null], ['Protocol', $row['timing']['protocol'] ?? null], ['Redirects followed', $row['timing']['redirects'] ?? null]] as [$label, $value])
                        @if ($value)
                            <span><span class="text-muted">{{ $label }}</span>
                                <span class="font-mono">{{ $value }}</span></span>
                        @endif
                    @endforeach
                </div>
            </div>
        </x-monocle::block>
    @endif

    @isset($row['exception'])
        <x-monocle::block title="Exception" :copy="$chain">
            <x-monocle::exception :exception="$row['exception']" />
        </x-monocle::block>
    @endisset

    <div class="grid gap-4 lg:grid-cols-2">
        <x-monocle::block title="Request headers">
            <x-monocle::pairs :pairs="Format::headers($row['request_headers'] ?? [])" what="request header" />
        </x-monocle::block>
        <x-monocle::block title="Response headers">
            <x-monocle::pairs :pairs="Format::headers($row['response_headers'] ?? [])" what="response header" />
        </x-monocle::block>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-monocle::block
            title="Payload"
            :copy="isset($row['request_payload']) ? json_encode($row['request_payload']) : ($row['request_body'] ?? null)"
        >
            @if (! empty($row['request_payload']))
                @php($formats = $row['request_formats'] ?? [])
                <x-monocle::pairs
                    :pairs="array_values(array_filter(Format::dot($row['request_payload']), fn (array $pair): bool => ! isset($formats[$pair[0]])))"
                    what="payload"
                />
                @foreach ($formats as $key => $format)
                    <div class="text-muted mt-2 font-mono text-[11px]">{{ $key }}</div>
                    <pre
                        @if (($format['format'] ?? null) === 'graphql') data-monocle-graphql @endif
                        class="scroll-quiet border-line bg-panel m-0 max-h-80 overflow-auto rounded-md border p-2.5 font-mono text-xs [overflow-wrap:anywhere] whitespace-pre-wrap"
                    >{{ $format['text'] ?? data_get($row['request_payload'], $key) }}</pre>
                @endforeach
            @elseif (! empty($row['request_body']))
                <pre class="scroll-quiet border-line bg-panel m-0 max-h-80 overflow-auto rounded-md border p-2.5 font-mono text-xs [overflow-wrap:anywhere] whitespace-pre-wrap">{{ $row['request_body'] }}</pre>
            @else
                <span class="text-muted text-xs">none</span>
            @endif
        </x-monocle::block>
        <x-monocle::block title="Response" :copy="$raw">
            @if (! empty($response['cards']))
                <div data-monocle-cards="{{ json_encode($response['cards']) }}"></div>
                @if ($raw)
                    <details>
                        <summary class="text-muted cursor-pointer text-xs">Raw body</summary>
                        <pre class="scroll-quiet border-line bg-panel mt-1.5 max-h-80 overflow-auto rounded-md border p-2.5 font-mono text-xs [overflow-wrap:anywhere] whitespace-pre-wrap">{{ $pretty($raw) }}</pre>
                    </details>
                @endif
            @elseif ($raw)
                <pre class="scroll-quiet border-line bg-panel m-0 max-h-80 overflow-auto rounded-md border p-2.5 font-mono text-xs [overflow-wrap:anywhere] whitespace-pre-wrap">{{ $pretty($raw) }}</pre>
            @else
                <span class="text-muted text-xs">not recorded</span>
            @endif
        </x-monocle::block>
    </div>

    <x-monocle::origin :origin="$row['origin'] ?? null" />
</div>
