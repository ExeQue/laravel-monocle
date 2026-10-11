{{-- A tab panel, shown while its tab is open (or while `show`, an Alpine expression, is true). --}}
@props(['name', 'show' => null])
<section
    id="monocle-panel-{{ $name }}"
    role="tabpanel"
    :aria-labelledby="'monocle-tab-' + tab"
    tabindex="0"
    x-show="{{ $show ?? "tab === '{$name}'" }}"
    {{ $attributes }}
>
    {{ $slot }}
</section>
