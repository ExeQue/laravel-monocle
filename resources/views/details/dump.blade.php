{{-- One dump: the value as VarDumper printed it, where it was dumped and whether dd() ended the request. --}}
@use(ExeQue\Monocle\Dashboard\Format)
<div class="grid gap-3 text-xs">
    <x-monocle::block title="Value" :copy="$row['value'] ?? ''">
        <pre class="scroll-quiet border-line bg-panel m-0 max-h-96 overflow-auto rounded-md border p-2.5 font-mono text-xs whitespace-pre">{!! Format::dump($row) !!}</pre>
    </x-monocle::block>

    <x-monocle::facts
        :items="[
            ['Called through', ! empty($row['is_dd']) ? 'dd()' : 'dump()'],
            ['Started at', isset($row['time']) ? Format::ms($row['time']) : null],
        ]"
    />

    <x-monocle::origin :origin="$row['origin'] ?? null" />
</div>
