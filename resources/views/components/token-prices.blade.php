{{-- Prices per million tokens for the models a section used, kept in this browser; with a price for each model the tokens get an estimated cost among the totals. --}}
@props(['section'])
<div class="mb-4 grid gap-2" x-data="{ pricesOpen: false }" x-show="tokenModels('{{ $section }}').length">
    <x-monocle::fold-heading open="pricesOpen">Token prices</x-monocle::fold-heading>
    <div class="grid gap-2" x-show="pricesOpen" x-collapse x-cloak>
        <p class="text-muted m-0 text-xs">
            USD per million tokens, kept in this browser only. Cached is input read from the provider's cache. The
            estimated cost shows once every model has a price.
        </p>
        <div class="overflow-x-auto">
            <table class="text-[13px]">
                <thead>
                    <tr class="text-muted text-left text-xs">
                        <th class="pr-4 font-medium">Model</th>
                        <th class="pr-2 font-medium">Input</th>
                        <th class="pr-2 font-medium">Cached</th>
                        <th class="font-medium">Output</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="[model] in tokenModels('{{ $section }}')" :key="model">
                        <tr>
                            <td class="pr-4 font-mono" x-text="model"></td>
                            <template x-for="kind in ['input', 'cached', 'output']" :key="kind">
                                <td class="py-1 pr-2">
                                    <input
                                        type="number"
                                        min="0"
                                        step="any"
                                        class="border-line bg-bg text-fg w-24 rounded-md border px-2 py-1 tabular-nums"
                                        :aria-label="`${kind} price for ${model}`"
                                        :value="prices[model]?.[kind] ?? ''"
                                        @change="setPrice(model, kind, $event.target.value)"
                                    />
                                </td>
                            </template>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</div>
