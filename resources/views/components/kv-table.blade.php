{{--
    A key/value table, one row per [k, v] pair of `rows` (an Alpine expression), each value with
    a copy button. Keys: "dotted" tones down the parents of dot-notated keys (flattened nested
    data), "header" cuts long names with the full name in a tooltip. `value` is an Alpine
    expression giving the value's HTML.
--}}
@props(['rows', 'keys' => 'dotted', 'value' => 'payloadValue(v)'])
<div :class="cls.tableWrap" {{ $attributes }}>
    <table class="w-full border-collapse text-[13px]">
        <tbody>
            <template x-for="[k, v] in {{ $rows }}" :key="k">
                <tr>
                    @if ($keys === 'header')
                        <td
                            :class="cls.td"
                            class="text-muted w-0 pr-6 font-mono text-xs ![overflow-wrap:normal] whitespace-nowrap"
                            :data-tip="k.length > 32 ? k : ''"
                            x-text="shortName(k)"
                        ></td>
                    @else
                        <td
                            :class="cls.td"
                            class="w-0 pr-6 font-mono text-xs ![overflow-wrap:normal] whitespace-nowrap"
                            x-html="dottedKey(k)"
                        ></td>
                    @endif
                    <td :class="cls.td" class="group/copy font-mono text-xs">
                        <span x-html="{{ $value }}"></span>
                        <x-monocle::copy bind="copyText(v)" what-bind="k" hover />
                    </td>
                </tr>
            </template>
        </tbody>
    </table>
</div>
