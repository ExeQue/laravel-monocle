@php
    use Workbench\App\Demos\Catalog;

    $view = request('view') === 'alarms' ? 'alarms' : 'lens';
    $showcase = Catalog::showcase();
    $lenses = Catalog::lenses();
    $byCategory = collect($lenses)->groupBy('category');
    $alarms = Catalog::alarms();
@endphp

@extends('layout', ['title' => 'Monocle demos'])

@push('head')
    <style>
        .tabs {
            display: inline-flex;
            gap: 4px;
            padding: 4px;
            border-radius: 10px;
            background: var(--sunk);
        }
        .tabs a {
            min-height: 36px;
            display: inline-flex;
            align-items: center;
            padding: 0 14px;
            border-radius: 8px;
            color: var(--muted);
        }
        .tabs a[aria-current='page'] {
            background: var(--brass);
            color: var(--on-brass);
            font-weight: 600;
        }
        .layout {
            display: flex;
            flex-wrap: wrap;
            gap: 32px;
            align-items: flex-start;
        }
        .side {
            flex: 1 1 240px;
            max-width: 260px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .side input[type='search'] {
            width: 100%;
        }
        .side nav a {
            display: flex;
            justify-content: space-between;
            align-items: center;
            min-height: 32px;
            padding: 0 10px;
            border-radius: 6px;
            color: var(--fg);
        }
        .side nav a:hover {
            background: var(--sunk);
        }
        .side nav .eyebrow {
            padding: 10px 10px 4px;
            display: block;
        }
        .count {
            font:
                12px ui-monospace,
                Menlo,
                monospace;
            color: var(--muted);
            display: inline-flex;
            gap: 6px;
            align-items: center;
        }
        .dot {
            width: 7px;
            height: 7px;
            border-radius: 999px;
            background: var(--warn);
        }
        .content {
            flex: 999 1 560px;
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 28px;
        }
        .hero {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            align-items: center;
        }
        .hero > div {
            flex: 999 1 420px;
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .hero p {
            margin: 0;
            color: var(--muted);
            max-width: 70ch;
        }
        .hero .go {
            min-height: 48px;
            padding: 0 22px;
            border-radius: 10px;
            background: var(--fg);
            color: var(--bg);
            font:
                600 14px ui-monospace,
                Menlo,
                monospace;
            display: inline-flex;
            align-items: center;
        }
        .lens-head {
            display: flex;
            align-items: baseline;
            gap: 10px;
            margin-bottom: 10px;
        }
        .demos {
            list-style: none;
            margin: 0;
            padding: 0;
            border-radius: 10px;
            border: 1px solid var(--line);
            background: var(--panel);
            overflow: hidden;
        }
        .demo + .demo {
            border-top: 1px solid var(--sunk);
        }
        .demo-row {
            display: grid;
            grid-template-columns: 56px minmax(0, 1fr) auto;
            gap: 14px;
            align-items: center;
            padding: 12px 16px;
            color: var(--fg);
            width: 100%;
        }
        .demo-row:hover {
            background: var(--sunk);
            color: var(--fg);
        }
        form.demo-row button.demo-main {
            all: unset;
            cursor: pointer;
        }
        .demo-main {
            display: flex;
            flex-direction: column;
            gap: 2px;
            min-width: 0;
        }
        .demo-name {
            font-weight: 600;
        }
        .demo-text {
            color: var(--muted);
            font-size: 13px;
        }
        .chips {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 6px;
            max-width: 320px;
        }
        .hero .chips {
            justify-content: flex-start;
            max-width: none;
        }
        .alarm-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 12px;
        }
        .alarm {
            display: flex;
            flex-direction: column;
            gap: 6px;
            padding: 16px;
            border-radius: 10px;
            background: var(--panel);
            border: 1px solid var(--line);
            border-top: 3px solid var(--warn);
            color: var(--fg);
        }
        .alarm.bad {
            border-top-color: var(--bad);
        }
        .alarm:hover {
            border-color: var(--brass);
            color: var(--fg);
        }
        .alarm .route {
            font:
                12px ui-monospace,
                Menlo,
                monospace;
            color: var(--brass);
        }
        [hidden] {
            display: none !important;
        }
    </style>
@endpush

@section('content')
    <div class="layout">
        <aside class="side">
            <div class="tabs" role="navigation" aria-label="Arrange demos">
                <a href="/" @if ($view === 'lens') aria-current="page" @endif>By lens</a>
                <a href="/?view=alarms" @if ($view === 'alarms') aria-current="page" @endif>By alarm</a>
            </div>

            @if ($view === 'lens')
                <label>
                    <span class="eyebrow">Find a demo</span>
                    <input type="search" id="search" placeholder="slow, n+1, impersonate, 500..." autocomplete="off" />
                </label>
                <label style="flex-direction: row; align-items: center; gap: 8px">
                    <input type="checkbox" id="alarms-only" style="min-height: auto" />
                    <span>Only demos that raise an alarm</span>
                </label>

                <nav aria-label="Lenses">
                    @foreach (Catalog::CATEGORIES as $category)
                        @continue(! $byCategory->has($category))
                        <span class="eyebrow">{{ $category }}</span>
                        @foreach ($byCategory[$category] as $lens)
                            @php($raises = collect($lens['demos'])->contains(fn (array $demo): bool => collect($demo['alarms'] ?? [])->contains(fn (array $alarm): bool => $alarm[1] !== 'info')))
                            <a href="#{{ $lens['id'] }}">
                                <span>{{ $lens['name'] }}</span>
                                <span class="count">
                                    @if ($raises)
                                        <span class="dot" title="Has alarm demos"></span>
                                    @endif
                                    {{ count($lens['demos']) }}</span>
                            </a>
                        @endforeach
                    @endforeach
                </nav>
            @endif

            <p class="muted" style="margin: 0; font-size: 13px">
                Seeded users have the password <code>password</code>, e.g.
                <code>ada@example.com</code
                >. {{ auth()->check() ? 'Logged in as '.auth()->user()->email.'.' : 'Not logged in.' }}
            </p>
        </aside>

        <main class="content">
            @if ($view === 'lens')
                <section class="card hero">
                    <div>
                        <p class="eyebrow" style="color: var(--brass)">Start here</p>
                        <h1>{{ $showcase['name'] }}</h1>
                        <p>{{ $showcase['text'] }}</p>
                        @include('demos.chips', ['alarms' => $showcase['alarms']])
                    </div>
                    <a class="go" href="{{ $showcase['path'] }}">GET {{ $showcase['path'] }}</a>
                </section>

                @foreach (Catalog::CATEGORIES as $category)
                    @foreach ($byCategory[$category] ?? [] as $lens)
                        <section id="{{ $lens['id'] }}" class="lens">
                            <div class="lens-head">
                                <h2>{{ $lens['name'] }}</h2>
                                <span class="muted">{{ $lens['blurb'] }}</span>
                            </div>
                            <ul class="demos">
                                @foreach ($lens['demos'] as $demo)
                                    @include('demos.link', ['demo' => $demo])
                                @endforeach
                            </ul>
                        </section>
                    @endforeach
                @endforeach
                <p id="nothing" class="muted" hidden>No demo matches.</p>
            @else
                <div style="display: flex; flex-direction: column; gap: 6px">
                    <h1>Every warning and error, and the demo that shows it</h1>
                    <p class="muted" style="margin: 0; max-width: 72ch">
                        Run a demo, then open its capture: the alarm is in the overview and on the tab it came from.
                    </p>
                </div>
                @foreach (['bad' => 'Errors', 'warn' => 'Warnings'] as $tone => $heading)
                    <section style="display: flex; flex-direction: column; gap: 12px">
                        <div class="lens-head" style="margin: 0">
                            <span class="dot" style="width: 10px; height: 10px; background: var(--{{ $tone }})"></span>
                            <h2>{{ $heading }}</h2>
                            <span class="muted">{{ count($alarms[$tone]) }} demos</span>
                        </div>
                        <div class="alarm-grid">
                            @foreach ($alarms[$tone] as $alarm)
                                <a class="alarm {{ $tone }}" href="{{ $alarm['demo']['path'] }}">
                                    <span
                                        style="
                                            display: flex;
                                            justify-content: space-between;
                                            gap: 8px;
                                            align-items: baseline;
                                        "
                                    >
                                        <strong>{{ $alarm['label'] }}</strong>
                                        <span
                                            class="muted"
                                            style="font-size: 12px; white-space: nowrap"
                                        >{{ $alarm['lens'] }}</span>
                                    </span>
                                    <span class="muted" style="font-size: 13px"
                                        >{{ $alarm['demo']['name'] }}: {{ $alarm['demo']['text'] }}</span>
                                    <span class="route">{{ $alarm['demo']['method'] }} {{ $alarm['demo']['path'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            @endif
        </main>
    </div>
@endsection

@push('scripts')
    <script>
        // Filters the demo rows as you type; lenses with nothing left are hidden.
        const search = document.getElementById('search');
        const alarmsOnly = document.getElementById('alarms-only');

        function filter() {
            const words = search.value.toLowerCase().split(/\s+/).filter(Boolean);
            let any = false;

            document.querySelectorAll('.lens').forEach((lens) => {
                let shown = 0;

                lens.querySelectorAll('.demo').forEach((demo) => {
                    const matches = words.every((word) => demo.dataset.search.includes(word));
                    const visible = matches && (!alarmsOnly.checked || demo.dataset.alarm === '1');
                    demo.hidden = !visible;
                    shown += visible ? 1 : 0;
                });

                lens.hidden = shown === 0;
                any ||= shown > 0;
            });

            document.getElementById('nothing').hidden = any;
        }

        search?.addEventListener('input', filter);
        alarmsOnly?.addEventListener('change', filter);
    </script>
@endpush
