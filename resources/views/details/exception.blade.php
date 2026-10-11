{{-- A reported exception: the chain it wraps, where it was thrown and the stack. --}}
@php($chain = implode("\n", array_map(fn (array $e): string => $e['class'].': '.($e['message'] ?? ''), [$row, ...($row['previous'] ?? [])])))
<div class="grid gap-3">
    <x-monocle::block title="Exception" :copy="$chain">
        <x-monocle::exception :exception="$row" />
    </x-monocle::block>

    @if (! empty($row['frames']))
        <x-monocle::block title="Stack ({{ count($row['frames']) }})" :copy="implode(PHP_EOL, $row['frames'])">
            <ol class="m-0 grid list-decimal gap-0.5 pl-6 text-[11.5px] [overflow-wrap:anywhere]">
                @foreach ($row['frames'] as $frame)
                    @php([$where, $call] = array_pad(explode(' ', (string) $frame, 2), 2, null))
                    <li class="font-mono">
                        <span class="text-brass"><x-monocle::name :value="$where" /></span>
                        @if ($call)
                            <span class="text-muted"><x-monocle::name :value="$call" /></span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </x-monocle::block>
    @endif

    <x-monocle::facts :items="[['Code', ! empty($row['code']) ? $row['code'] : null], ['Started at', isset($row['time']) ? \ExeQue\Monocle\Dashboard\Format::ms($row['time']) : null]]" />
</div>
