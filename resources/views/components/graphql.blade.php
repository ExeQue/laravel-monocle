{{-- A GraphQL document, printed one field per line in a labelled block with a copy button; the dashboard colours it. --}}
@props(['query', 'title' => 'Query'])
@php($pretty = \ExeQue\Monocle\Lenses\Packages\GraphQL\QueryFormatter::pretty($query))
<x-monocle::block :title="$title" :copy="$pretty">
    <pre
        data-monocle-graphql
        class="scroll-quiet border-line bg-panel m-0 max-h-80 overflow-auto rounded-md border p-2.5 font-mono text-xs [overflow-wrap:anywhere] whitespace-pre-wrap"
    >{{ $pretty }}</pre>
</x-monocle::block>
