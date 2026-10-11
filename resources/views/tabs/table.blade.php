{{--
    The tab of a section with no view of its own: its rows as a table. With `section` null it is the
    tab for sections no lens records (custom sections), shown for any tab not in `known`.
--}}
@php($section ??= null)
@php($known ??= [])
@php($open = $section === null ? '! '.\Illuminate\Support\Js::from($known).'.includes(tab)' : "tab === '{$section}'")
<x-monocle::panel :name="$section ?? 'rows'" :show="$open">
    <template x-if="{{ $open }}">
        <div><x-monocle::section-table /></div>
    </template>
</x-monocle::panel>
