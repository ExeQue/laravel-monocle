{{-- Short facts side by side: each [label, value] or [label, value, tone class]; empty values are left out. --}}
@props(['items'])
<dl class="m-0 grid grid-cols-[repeat(auto-fill,minmax(110px,1fr))] gap-3">
    @foreach (array_filter($items, fn (array $item): bool => ($item[1] ?? null) !== null && $item[1] !== '') as $item)
        <div class="grid gap-0.5">
            <dt class="text-muted text-[11px] tracking-wider uppercase">{{ $item[0] }}</dt>
            <dd @class(['m-0 text-[13px] tabular-nums [overflow-wrap:anywhere]', $item[2] ?? ''])>{{ $item[1] }}</dd>
        </div>
    @endforeach
</dl>
