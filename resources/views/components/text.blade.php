{{-- Long text in a labelled block: preformatted, scrolling inside its own box, with a copy button. --}}
@props(['title', 'text'])
<x-monocle::block :title="$title" :copy="$text">
    <pre class="scroll-quiet border-line bg-panel m-0 max-h-80 overflow-auto rounded-md border p-2.5 font-mono text-xs [overflow-wrap:anywhere] whitespace-pre-wrap">{{ $text }}</pre>
</x-monocle::block>
