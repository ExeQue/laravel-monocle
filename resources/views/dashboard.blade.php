<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex, nofollow" />
    <title>Monocle - {{ config('app.name') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ $assets['icon'] }}" />
    <link rel="stylesheet" href="{{ $assets['css'] }}" />
    <script defer src="{{ $assets['js'] }}"></script>
</head>
<body class="min-[861px]:h-dvh min-[861px]:overflow-hidden">
    <div
        class="flex min-h-dvh flex-col min-[861px]:grid min-[861px]:h-dvh min-[861px]:grid-cols-[minmax(280px,340px)_minmax(0,1fr)]"
        x-data="monocle(@js(['api' => $api, 'csrf' => csrf_token(), 'paths' => $paths, 'tabs' => array_keys($tabs)]))"
        x-cloak
    >
        <x-monocle::sidebar :icon="$assets['icon']" />

        <main
            class="scroll-quiet flex min-h-0 min-w-0 flex-1 flex-col gap-5 px-4 pt-5 pb-10 min-[861px]:[scrollbar-gutter:stable] min-[861px]:overflow-y-auto min-[861px]:overscroll-contain min-[861px]:px-6"
            x-ref="detail"
            :aria-busy="tabLoading"
        >
            <p class="sr-only" role="status" aria-live="polite" x-text="status"></p>
            <p :class="cls.empty" x-show="! capture">Select a capture.</p>
            <template x-if="capture">
                <div class="flex min-w-0 flex-col gap-5">
                    <x-monocle::capture.header />

                    <x-monocle::panels.overview />

                    @include('monocle::tabs.timeline')

                    {{-- One tab per lens: its own view, or the table. --}}
                    @foreach ($tabs as $section => $view)
                        @include($view, ['section' => $section])
                    @endforeach

                    @include('monocle::tabs.trace')

                    @include('monocle::tabs.table', ['section' => null, 'known' => ['overview', 'timeline', 'trace', ...array_keys($tabs)]])
                    <p class="text-muted text-xs" x-show="tabLoading">Loading...</p>
                    <p
                        class="text-muted border-line border-t pt-3 text-xs"
                        x-show="! tabLoading && tabOverhead !== null"
                        x-text="
                            'Monocle spent ' +
                            ms(tabOverhead) +
                            ' recording this, not counting the application code it called.'
                        "
                    ></p>
                </div>
            </template>
        </main>

        <x-monocle::palette />
    </div>
</body>
</html>
