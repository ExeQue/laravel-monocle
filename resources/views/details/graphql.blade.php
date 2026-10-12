{{-- One GraphQL operation: its facts, the root fields with their resolvers and definitions, the query, variables and errors. --}}
@php
    use ExeQue\Monocle\Dashboard\Format;

    $th = 'border-line border-b px-2 py-1 font-medium';
    $td = 'border-line border-b px-2 py-1 align-top';
    $fields = $row['fields'] ?? [];
    $errors = $row['errors'] ?? [];
    $variables = $row['variables'] ?? [];
    $hasDefinitions = array_filter(array_column($fields, 'definition')) !== [];
@endphp
<div class="grid gap-4">
    <x-monocle::facts
        :items="[
            ['Status', $row['status'] ?? null, ($row['status'] ?? null) === 'ok' ? 'text-ok' : 'text-bad'],
            ['Server', $row['server'] ?? null],
            // The table shows the schema in full, with a link; the name is enough here.
            ['Schema', isset($row['schema']) ? basename($row['schema']) : null],
            ['Type', $row['type'] ?? null],
            ['Operation', $row['operation'] ?? null],
            ['Duration', isset($row['duration']) ? Format::ms($row['duration']) : null],
            ['Started at', isset($row['time']) ? Format::ms($row['time']) : null],
        ]"
    />

    @if ($fields !== [])
        <x-monocle::block title="Fields">
            <table class="w-full border-collapse text-xs">
                <thead>
                    <tr class="text-muted text-left text-[11px] tracking-wider uppercase">
                        <th class="{{ $th }}">Field</th>
                        <th class="{{ $th }}">Resolver</th>
                        @if ($hasDefinitions)
                            <th class="{{ $th }}">Definition</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($fields as $field)
                        <tr>
                            <td class="{{ $td }} w-0 font-mono whitespace-nowrap">{{ $field['field'] ?? '' }}</td>
                            <td class="{{ $td }} font-mono [overflow-wrap:anywhere]">
                                <x-monocle::name :value="$field['resolver'] ?? ''" full />
                            </td>
                            @if ($hasDefinitions)
                                <td class="{{ $td }} font-mono [overflow-wrap:anywhere]">
                                    @if (! empty($field['definition']))
                                        <x-monocle::name :value="$field['definition']" />
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-monocle::block>
    @endif

    @if (! empty($row['query']))
        <x-monocle::graphql :query="$row['query']" />
    @endif

    @if ($variables !== [])
        <x-monocle::text
            title="Variables"
            :text="json_encode($variables, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)"
        />
    @endif

    @if ($errors !== [])
        <x-monocle::block title="Errors ({{ count($errors) }})">
            <div class="grid gap-2">
                @foreach ($errors as $error)
                    <div class="border-line bg-panel grid gap-1 rounded-md border p-2.5 text-xs">
                        <div class="text-bad [overflow-wrap:anywhere]">{{ $error['message'] ?? '' }}</div>
                        <div class="text-muted flex flex-wrap gap-x-4 font-mono">
                            @if (! empty($error['path']))
                                <span>path {{ implode('.', $error['path']) }}</span>
                            @endif
                            @foreach ($error['locations'] ?? [] as $location)
                                <span>line {{ $location['line'] ?? '?' }}:{{ $location['column'] ?? '?' }}</span>
                            @endforeach
                        </div>
                        @if (! empty($error['exception']))
                            <x-monocle::exception :exception="$error['exception']" />
                        @endif
                    </div>
                @endforeach
            </div>
        </x-monocle::block>
    @endif
</div>
