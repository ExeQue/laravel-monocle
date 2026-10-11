{{-- One query: as it ran, with placeholders, its bindings, where it ran and how it was called. --}}
@use(ExeQue\Monocle\Dashboard\Format)
@php($bindings = $row['bindings'] ?? [])
<div class="grid gap-3 text-xs">
    @if (! empty($row['sql_with_bindings']))
        <x-monocle::block title="Query" :copy="$row['sql_with_bindings']">
            <code class="block font-mono [overflow-wrap:anywhere] whitespace-pre-wrap">{{ $row['sql_with_bindings'] }}</code>
        </x-monocle::block>
    @endif

    <x-monocle::block title="Query with placeholders" :copy="$row['sql'] ?? ''">
        <code class="block font-mono [overflow-wrap:anywhere] whitespace-pre-wrap">{{ $row['sql'] ?? '' }}</code>
    </x-monocle::block>

    <x-monocle::block title="Bindings" :copy="$bindings === [] ? null : json_encode($bindings)">
        @if ($bindings === [])
            <span class="text-muted">none</span>
        @else
            <table class="w-fit border-collapse">
                <tbody>
                    @foreach ($bindings as $index => $binding)
                        <tr>
                            <td class="text-muted pr-3 font-mono tabular-nums">?{{ $index + 1 }}</td>
                            <td class="font-mono [overflow-wrap:anywhere]">
                                @if ($binding === null)
                                    <span class="text-muted">null</span>
                                @else
                                    {{ Format::value($binding) }}
                                @endif
                            </td>
                            <td class="pl-2">
                                @if ($binding !== null)
                                    <x-monocle::copy :text="Format::value($binding)" :what="'binding '.($index + 1)" />
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-monocle::block>

    <x-monocle::facts
        :items="[
            ['Connection', $row['connection'] ?? null],
            ['Driver', $row['driver'] ?? null],
            ['Type', $row['type'] ?? null],
            ['Tables', implode(', ', $row['tables'] ?? [])],
            ['Transaction', $row['transaction_id'] ?? null],
            ['Started at', isset($row['time']) ? Format::ms($row['time']) : null],
            ['Duration', isset($row['duration']) ? Format::ms($row['duration']) : null],
        ]"
    />

    <x-monocle::origin :origin="$row['origin'] ?? null" />
</div>
