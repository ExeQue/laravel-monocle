{{--
    A row of toggle chips. `options` is an Alpine expression giving a list of [key, label, ...] arrays;
    in `active` and `pick` the current one is `option`. The slot replaces the chip's text.
--}}
@props(['options', 'active', 'pick', 'label', 'lead' => 'Show'])
<div
    {{ $attributes->class(['text-muted mb-2.5 flex flex-wrap items-center gap-1.5 text-xs']) }}
    role="group"
    aria-label="{{ $label }}"
>
    @if ($lead)
        <span>{{ $lead }}</span>
    @endif
    <template x-for="option in {{ $options }}" :key="option[0]">
        <button type="button" :class="cls.chip" class="gap-1.5" @click="{{ $pick }}" :aria-pressed="{{ $active }}">
            @if ($slot->isEmpty())
                <span x-text="option[1]"></span>
            @else
                {{ $slot }}
            @endif
        </button>
    </template>
</div>
