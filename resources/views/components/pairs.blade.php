{{-- [key, value] pairs as a compact table with a copy button per value; JSON strings are shown formatted. --}}
@props(['pairs', 'what' => 'value'])
@if ($pairs === [])
    <span class="text-muted text-xs">none</span>
@else
    <table class="w-full border-collapse">
        @foreach ($pairs as [$key, $value])
            @php($text = \ExeQue\Monocle\Dashboard\Format::value($value))
            @php($json = is_string($value) && preg_match('/^\s*[\[{]/', $value) === 1 ? json_decode($value, true) : null)
            <tr>
                <td class="border-line text-muted w-0 border-t px-2 py-1 align-top font-mono text-xs whitespace-nowrap">
                    {{ $key }}
                </td>
                <td class="border-line border-t px-2 py-1 align-top font-mono text-xs [overflow-wrap:anywhere]">
                    @if ($value === null)
                        <span class="text-muted">null</span>
                    @elseif ($json !== null)
                        <pre class="m-0 max-h-64 overflow-auto break-all whitespace-pre-wrap">{{ json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                    @else
                        {{ $text }}
                    @endif
                </td>
                <td class="border-line w-0 border-t px-2 py-1 align-top">
                    @if ($value !== null)
                        <x-monocle::copy :text="$text" :what="$what.' '.$key" />
                    @endif
                </td>
            </tr>
        @endforeach
    </table>
@endif
