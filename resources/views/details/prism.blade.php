{{-- One Prism call: what kind of call, its facts and tokens, the prompt, the response or structured output and an error. --}}
@php
    use ExeQue\Monocle\Dashboard\Format;

    $status = $row['status'] ?? null;
    $tokens = $row['tokens'] ?? [];
    $chain = isset($row['exception']) ? implode("\n", array_map(fn (array $e): string => $e['class'].': '.($e['message'] ?? ''), [$row['exception'], ...($row['exception']['previous'] ?? [])])) : null;
    $model = trim(($row['provider'] ?? '').'/'.($row['model'] ?? ''), '/');
@endphp
<div class="grid gap-4">
    <div class="flex flex-wrap items-start gap-1.5 font-mono text-xs">
        <b>{{ str_replace('_', ' ', $row['operation'] ?? '') }}</b>
        <span class="[overflow-wrap:anywhere]">{{ $model }}</span>
        @if ($model !== '')
            <x-monocle::copy :text="$model" what="provider and model" />
        @endif
    </div>

    <x-monocle::facts
        :items="[
            ['Status', $status, match ($status) { 'ok' => 'text-ok', 'abandoned' => 'text-warn', default => 'text-bad' }],
            ['Provider', $row['provider'] ?? null],
            ['Model', $row['model'] ?? null],
            ['Finish reason', $row['finish_reason'] ?? null],
            ['Steps', ! empty($row['steps']) ? $row['steps'] : null],
            ['Tool calls', ! empty($row['tool_calls']) ? $row['tool_calls'] : null],
            ['Streamed', ! empty($row['streamed']) ? 'yes' : null],
            ['Nested', ! empty($row['nested']) ? 'yes, from a tool of another call' : null],
            ['Duration', isset($row['duration']) ? Format::ms($row['duration']) : null],
            ['Started at', isset($row['time']) ? Format::ms($row['time']) : null],
        ]"
    />

    <x-monocle::block title="Tokens">
        <x-monocle::facts
            :items="[
                ['Input', $tokens['input'] ?? 0],
                ['Output', $tokens['output'] ?? 0],
                ['Cache read', ($tokens['cache_read'] ?? 0) ?: null],
                ['Cache write', ($tokens['cache_write'] ?? 0) ?: null],
                ['Reasoning', ($tokens['reasoning'] ?? 0) ?: null],
            ]"
        />
    </x-monocle::block>

    @isset($row['exception'])
        <x-monocle::block title="Exception" :copy="$chain">
            <x-monocle::exception :exception="$row['exception']" />
        </x-monocle::block>
    @endisset

    @if (! empty($row['error']))
        <x-monocle::block title="Stream error" :copy="$row['error']">
            <span class="text-bad text-xs [overflow-wrap:anywhere]">{{ $row['error'] }}</span>
        </x-monocle::block>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        @isset($row['prompt'])
            <x-monocle::text title="Prompt" :text="$row['prompt']" />
        @else
            <x-monocle::block title="Prompt"><span class="text-muted text-xs">none</span></x-monocle::block>
        @endisset
        @if (isset($row['structured']))
            <x-monocle::text title="Structured output" :text="Format::pretty($row['structured'])" />
        @elseif (isset($row['response']) && $row['response'] !== '')
            <x-monocle::text title="Response" :text="$row['response']" />
        @else
            <x-monocle::block title="Response"><span class="text-muted text-xs">none</span></x-monocle::block>
        @endif
    </div>

    @if (! empty($row['tools']))
        <x-monocle::block title="Tools offered" :copy="implode(', ', $row['tools'])">
            <div class="flex flex-wrap gap-1">
                @foreach ($row['tools'] as $tool)
                    <span @class(['border-line inline-block rounded border px-1 font-mono text-[11px]', 'text-bad' => in_array($tool, $row['failed_tools'] ?? [], true)])>{{ $tool }}</span>
                @endforeach
            </div>
        </x-monocle::block>
    @endif

    <x-monocle::origin :origin="$row['origin'] ?? null" />
</div>
