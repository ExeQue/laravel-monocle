@php
    use ExeQue\Monocle\Eyepiece;
    use Workbench\App\Demos\Catalog;

    $found = Catalog::find(request()->path());
    $demo = $found['demo'] ?? null;
    // The capture of this very request, so the page can link straight to it.
    $captureId = app(Eyepiece::class)->active()?->id;
    $tab = $demo['tab'] ?? 'overview';
    $captureUrl = $captureId === null ? '/_monocle' : "/_monocle#{$captureId}/{$tab}";
    $tabLabel = \Illuminate\Support\Str::headline($tab);
    $look = $demo['look'] ?? [];

    if ($demo !== null && $look === []) {
        $look = [[$tabLabel, 'What this demo recorded.']];
    }

    $flags = collect($demo['alarms'] ?? [])->filter(fn (array $alarm): bool => $alarm[1] !== 'info')->values();
@endphp

@extends('layout', ['title' => $title])

@push('head')
    <style>
        .result {
            max-width: 1040px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        .result-head {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            align-items: flex-end;
            justify-content: space-between;
        }
        .result-head > div {
            flex: 999 1 420px;
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .result-head p {
            margin: 0;
            color: var(--muted);
            max-width: 66ch;
        }
        .result-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .result-actions .button {
            min-height: 48px;
            padding: 0 20px;
            border-radius: 10px;
        }
        .panels {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 16px;
        }
        .panels ul {
            margin: 0;
            padding: 0;
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .panels li {
            display: flex;
            gap: 12px;
            align-items: baseline;
        }
        .panels li > :first-child {
            font-weight: 600;
            min-width: 96px;
        }
        .flag {
            display: flex;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 8px;
            background: var(--warn-soft);
            color: var(--warn);
        }
        .flag.bad {
            background: var(--bad-soft);
            color: var(--bad);
        }
        .neighbours {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 12px;
            padding-top: 20px;
            border-top: 1px solid var(--line);
        }
        .neighbours a {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
    </style>
@endpush

@section('crumbs')
    <a href="/">Demos</a>
    @if ($found !== null)
        <span aria-hidden="true">/</span>
        <a href="/#{{ $found['lens']['id'] }}">{{ $found['lens']['name'] }}</a>
    @endif
    <span aria-hidden="true">/</span>
    <span style="color: var(--fg)">{{ $demo['name'] ?? $title }}</span>
@endsection

@section('content')
    <div class="result">
        <section class="result-head">
            <div>
                <div style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center">
                    <span class="method {{ request()->isMethod('post') ? 'post' : '' }}">{{ request()->method() }}</span>
                    <span class="mono muted">/{{ ltrim(request()->path(), '/') }}</span>
                </div>
                <h1>{{ $demo['name'] ?? $title }}</h1>
                @isset($demo['text'])
                    <p>{{ $demo['text'] }}</p>
                @endisset
            </div>
            <div class="result-actions">
                <a class="button primary" href="{{ $captureUrl }}" target="_blank">Open this capture</a>
                @if (request()->isMethod('post'))
                    <form method="POST" action="{{ request()->url() }}">
                        @csrf
                        <button type="submit" class="button" style="min-height: 48px; border-radius: 10px">
                            Run again
                        </button>
                    </form>
                @else
                    <a class="button" href="{{ request()->fullUrl() }}">Run again</a>
                @endif
            </div>
        </section>

        @if ($demo !== null)
            <section class="panels">
                <div class="card" style="display: flex; flex-direction: column; gap: 12px">
                    <h2 class="eyebrow">What to look for</h2>
                    <ul>
                        @foreach ($look as [$where, $what])
                            <li>
                                <a href="{{ $captureUrl }}" target="_blank">{{ $where }}</a
                                ><span class="muted">{{ $what }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                @if ($flags->isNotEmpty())
                    <div class="card" style="display: flex; flex-direction: column; gap: 12px">
                        <h2 class="eyebrow">Monocle will flag</h2>
                        @foreach ($flags as [$label, $tone])
                            <div class="flag {{ $tone }}">
                                <strong>{{ $tone === 'bad' ? 'Error' : 'Warning' }}</strong><span>{{ $label }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        @endif

        @isset($output)
            <section style="display: flex; flex-direction: column; gap: 10px">
                <div style="display: flex; justify-content: space-between; align-items: center">
                    <h2 class="eyebrow">What the demo returned</h2>
                    <button type="button" id="copy-output">Copy</button>
                </div>
                <pre id="output">{{ is_string($output) ? $output : json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </section>
        @endisset

        @if (($found['previous'] ?? null) !== null || ($found['next'] ?? null) !== null)
            <nav class="neighbours" aria-label="More demos">
                @if ($found['previous'])
                    <a href="{{ $found['previous']['path'] }}"
                        ><span class="muted" style="font-size: 12px">Previous in {{ $found['lens']['name'] }}</span
                        ><span>{{ $found['previous']['name'] }}</span></a>
                @else
                    <span></span>
                @endif
                @if ($found['next'])
                    <a href="{{ $found['next']['path'] }}" style="text-align: right"
                        ><span class="muted" style="font-size: 12px">Next</span
                        ><span>{{ $found['next']['name'] }}</span></a>
                @endif
            </nav>
        @endif
    </div>
@endsection

@push('scripts')
    <script>
        document.getElementById('copy-output')?.addEventListener('click', async (event) => {
            try {
                await navigator.clipboard.writeText(document.getElementById('output').textContent);
                event.target.textContent = 'Copied';
            } catch {
                getSelection().selectAllChildren(document.getElementById('output'));
            }
        });
    </script>
@endpush
