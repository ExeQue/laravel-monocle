{{-- One validation: a row per field with its rules, the validated input and what failed. --}}
@php
    use ExeQue\Monocle\Dashboard\Format;

    $failed = collect($row['failed'] ?? [])->groupBy('field');
    $fields = collect(array_keys($row['rules'] ?? []))->merge($failed->keys())->unique();
    $input = collect(Format::dot($row['input'] ?? []));
    $valueOf = fn (string $field): mixed => $input->first(fn (array $pair): bool => $pair[0] === $field)[1] ?? ($row['input'][explode('.', $field)[0]] ?? null);
    $th = 'px-2 py-1 font-medium';
    $td = 'border-line border-t px-2 py-1.5 align-top';
@endphp
<div class="grid gap-3">
    <table class="w-full border-collapse text-xs">
        <thead>
            <tr class="text-muted text-left text-[11px] tracking-wider uppercase">
                <th class="{{ $th }}">Field</th>
                <th class="{{ $th }}">Rules</th>
                <th class="{{ $th }}">Input</th>
                <th class="{{ $th }}">Result</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($fields as $field)
                @php($value = $valueOf($field))
                <tr>
                    <td class="{{ $td }} w-0 font-mono whitespace-nowrap">{{ $field }}</td>
                    <td class="{{ $td }}">
                        @foreach (is_array($row['rules'][$field] ?? null) ? $row['rules'][$field] : array_filter(explode('|', (string) ($row['rules'][$field] ?? ''))) as $rule)
                            <span class="border-line mr-1 inline-block rounded border px-1 font-mono text-[11px]"
                                ><x-monocle::name :value="$rule"
                            /></span>
                        @endforeach
                    </td>
                    <td class="{{ $td }} [overflow-wrap:anywhere]">
                        @if (is_array($value) && isset($value['file'], $value['size']))
                            <span class="font-mono">{{ $value['file'] }}</span>
                            <span class="text-muted">{{ Format::bytes($value['size']) }}{{ ! empty($value['mime']) ? ', '.$value['mime'] : '' }}</span>
                        @elseif ($value === null)
                            <span class="text-muted">{{ array_key_exists(explode('.', $field)[0], $row['input'] ?? []) ? 'null' : '-' }}</span>
                        @else
                            @php($text = Format::value($value))
                            <span class="group/copy">
                                <span
                                    class="font-mono"
                                    @if (mb_strlen($text) > 120) data-tip="{{ mb_substr($text, 0, 1000) }}" @endif
                                >{{ mb_strimwidth($text, 0, 121, '…') }}</span>
                                <x-monocle::copy :text="$text" :what="$field.' input'" hover />
                            </span>
                        @endif
                    </td>
                    <td class="{{ $td }}">
                        @forelse ($failed->get($field, []) as $failure)
                            <div class="text-bad">
                                <span class="font-mono text-[11px]">{{ $failure['rule'] }}{{ ! empty($failure['parameters']) ? '('.implode(', ', $failure['parameters']).')' : '' }}</span>
                                {{ $failure['message'] ?? '' }}
                            </div>
                        @empty
                            <span class="text-ok">passed</span>
                        @endforelse
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <x-monocle::facts
        :items="[
            ['Source', $row['source'] ?? null],
            ['Duration', isset($row['duration']) ? Format::ms($row['duration']) : null],
            ['Started at', isset($row['time']) ? Format::ms($row['time']) : null],
        ]"
    />

    <x-monocle::origin :origin="$row['origin'] ?? null" />
</div>
