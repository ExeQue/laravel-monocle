{{-- One agent run: the agent, its facts and tokens, the prompt, the response, an exception and the tool calls it made. --}}
@php
    use ExeQue\Monocle\Dashboard\Format;

    $status = $row['status'] ?? null;
    $tokens = $row['tokens'] ?? [];
    $tools = array_values(array_filter($capture->rows('ai_tools'), fn (array $tool): bool => ($tool['invocation_id'] ?? null) === ($row['invocation_id'] ?? false)));
    $parent = collect($capture->rows('ai'))->firstWhere('invocation_id', $row['parent_invocation_id'] ?? false);
    $chain = isset($row['exception']) ? implode("\n", array_map(fn (array $e): string => $e['class'].': '.($e['message'] ?? ''), [$row['exception'], ...($row['exception']['previous'] ?? [])])) : null;
    $th = 'px-2 py-1 font-medium';
    $td = 'border-line border-t px-2 py-1.5 align-top';
@endphp
<div class="grid gap-4">
    <div class="flex flex-wrap items-start gap-1.5 font-mono text-xs">
        <x-monocle::name :value="$row['agent'] ?? ''" class="font-semibold [overflow-wrap:anywhere]" full />
    </div>

    <x-monocle::facts
        :items="[
            ['Status', $status, $status === 'ok' ? 'text-ok' : 'text-bad'],
            ['Provider', $row['provider'] ?? null],
            ['Model', $row['model'] ?? null],
            ['Steps', $row['steps'] ?? null],
            ['Tool calls', $row['tool_calls'] ?? null],
            ['Streamed', ! empty($row['streamed']) ? 'yes' : null],
            ['Duration', isset($row['duration']) ? Format::ms($row['duration']) : null],
            ['Started at', isset($row['time']) ? Format::ms($row['time']) : null],
            ['Called by', isset($parent['agent']) ? class_basename($parent['agent']) : null],
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

    <div class="grid gap-4 lg:grid-cols-2">
        <x-monocle::text title="Prompt" :text="$row['prompt'] ?? ''" />
        @if (isset($row['structured']))
            <x-monocle::text title="Structured output" :text="Format::pretty($row['structured'])" />
        @elseif (isset($row['response']))
            <x-monocle::text title="Response" :text="$row['response']" />
        @else
            <x-monocle::block title="Response"><span class="text-muted text-xs">none</span></x-monocle::block>
        @endif
    </div>

    @if ($tools)
        <x-monocle::block title="Tool calls ({{ count($tools) }})">
            <table class="w-full border-collapse text-xs">
                <thead>
                    <tr class="text-muted text-left text-[11px] tracking-wider uppercase">
                        <th class="{{ $th }}">Tool</th>
                        <th class="{{ $th }}">Arguments</th>
                        <th class="{{ $th }}">Status</th>
                        <th class="{{ $th }} text-right">Duration</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($tools as $tool)
                        @php($arguments = Format::value($tool['arguments'] ?? null))
                        <tr>
                            <td class="{{ $td }} w-0 font-mono whitespace-nowrap">
                                {{ $tool['tool'] ?? '' }}
                                @if (! empty($tool['sub_agent']))
                                    <span class="border-line text-muted ml-1 inline-block rounded border px-1 text-[11px]">sub-agent</span>
                                    <span class="text-muted"><x-monocle::name :value="$tool['class'] ?? ''" /></span>
                                @endif
                            </td>
                            <td class="{{ $td }} font-mono [overflow-wrap:anywhere]">
                                <span
                                    @if (mb_strlen($arguments) > 120) data-tip="{{ mb_substr($arguments, 0, 1000) }}" @endif
                                >{{ mb_strimwidth($arguments, 0, 121, '...') }}</span>
                            </td>
                            <td @class([$td, 'w-0 whitespace-nowrap', ($tool['status'] ?? null) === 'ok' ? 'text-ok' : 'text-bad'])>
                                {{ $tool['status'] ?? '' }}
                            </td>
                            <td class="{{ $td }} w-0 text-right whitespace-nowrap tabular-nums">
                                {{ Format::ms($tool['duration'] ?? 0) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-monocle::block>
    @endif

    <x-monocle::origin :origin="$row['origin'] ?? null" />
</div>
