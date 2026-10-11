@php
    // Jobs waiting on the database queue, for the "Work the queue" button.
    try {
        $waiting = \Illuminate\Support\Facades\DB::table('jobs')->count();
    } catch (\Throwable) {
        $waiting = null;
    }
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ $title ?? 'Monocle demos' }}</title>
    <script>
        // Set before the page paints, so a chosen theme never flashes the other one.
        try {
            const theme = localStorage.getItem('monocle.theme');
            if (theme === 'light' || theme === 'dark') document.documentElement.dataset.theme = theme;
        } catch {}
    </script>
    <style>
        /* Monocle's own palette, light and dark. */
        :root {
            --bg: #f5f3ef;
            --panel: #ffffff;
            --sunk: #ede9e2;
            --line: #ddd6cb;
            --fg: #1d1b18;
            --muted: #6b645a;
            --brass: #a36a1e;
            --brass-hover: #7d4f12;
            --on-brass: #ffffff;
            --ok: #2f7d4f;
            --warn: #9a5b00;
            --warn-soft: #fbecd2;
            --bad: #b3261e;
            --bad-soft: #f9dedc;
            --get: #1b6f69;
            --get-soft: #e3f1ef;
            --post: #3b5f80;
            --post-soft: #e6eef6;
            --code: #1d1b18;
            --on-code: #ece8e1;
            color-scheme: light;
        }
        /* Auto follows the system; the switcher sets data-theme, shared with the dashboard. */
        @media (prefers-color-scheme: dark) {
            :root:not([data-theme='light']) {
                --bg: #111214;
                --panel: #17181b;
                --sunk: #1e2024;
                --line: #2c2e33;
                --fg: #ece8e1;
                --muted: #9b958c;
                --brass: #e9b25a;
                --brass-hover: #f3c97f;
                --on-brass: #17181b;
                --ok: #6cc08f;
                --warn: #f0b45a;
                --warn-soft: #3a2a12;
                --bad: #f08a80;
                --bad-soft: #3d1c1a;
                --get: #6fd6cc;
                --get-soft: #17363a;
                --post: #7ab3e8;
                --post-soft: #1b2b3d;
                --code: #1e2024;
                --on-code: #ece8e1;
                color-scheme: dark;
            }
        }
        :root[data-theme='dark'] {
            --bg: #111214;
            --panel: #17181b;
            --sunk: #1e2024;
            --line: #2c2e33;
            --fg: #ece8e1;
            --muted: #9b958c;
            --brass: #e9b25a;
            --brass-hover: #f3c97f;
            --on-brass: #17181b;
            --ok: #6cc08f;
            --warn: #f0b45a;
            --warn-soft: #3a2a12;
            --bad: #f08a80;
            --bad-soft: #3d1c1a;
            --get: #6fd6cc;
            --get-soft: #17363a;
            --post: #7ab3e8;
            --post-soft: #1b2b3d;
            --code: #1e2024;
            --on-code: #ece8e1;
            color-scheme: dark;
        }
        .themes {
            display: inline-flex;
            gap: 2px;
            padding: 3px;
            border-radius: 999px;
            border: 1px solid var(--line);
        }
        .themes button {
            min-height: 30px;
            padding: 0 10px;
            border: 0;
            border-radius: 999px;
            background: transparent;
            color: var(--muted);
            font-size: 13px;
        }
        .themes button[aria-pressed='true'] {
            background: var(--brass);
            color: var(--on-brass);
        }
        * {
            box-sizing: border-box;
        }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--fg);
            font:
                14px/1.5 ui-sans-serif,
                system-ui,
                -apple-system,
                'Segoe UI',
                sans-serif;
        }
        a {
            color: var(--brass);
            text-decoration: none;
        }
        a:hover {
            color: var(--brass-hover);
        }
        code,
        pre,
        .mono {
            font-family: ui-monospace, 'SFMono-Regular', Menlo, Consolas, monospace;
        }
        h1 {
            margin: 0;
            font-size: 28px;
            line-height: 1.2;
            text-wrap: balance;
        }
        h2 {
            margin: 0;
            font-size: 18px;
        }
        pre {
            margin: 0;
            padding: 18px 20px;
            border-radius: 12px;
            background: var(--code);
            color: var(--on-code);
            font-size: 13px;
            line-height: 1.6;
            overflow-x: auto;
        }
        button,
        .button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 40px;
            padding: 0 14px;
            border-radius: 8px;
            border: 1px solid var(--line);
            background: var(--panel);
            color: var(--fg);
            font: inherit;
            cursor: pointer;
        }
        button:hover,
        .button:hover {
            border-color: var(--brass);
            color: var(--fg);
        }
        .button.primary {
            background: var(--brass);
            border-color: var(--brass);
            color: var(--on-brass);
            font-weight: 600;
        }
        .button.primary:hover {
            color: var(--on-brass);
            filter: brightness(1.08);
        }
        .button.danger {
            color: var(--bad);
        }
        input,
        select,
        textarea {
            font: inherit;
            color: var(--fg);
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            min-height: 40px;
            padding: 0 12px;
        }
        label {
            display: inline-flex;
            flex-direction: column;
            gap: 4px;
        }
        form {
            margin: 0;
        }
        .muted {
            color: var(--muted);
        }
        .errors {
            color: var(--bad);
        }
        .eyebrow {
            font-size: 12px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 600;
            margin: 0;
        }
        .card {
            padding: 20px;
            border-radius: 12px;
            background: var(--panel);
            border: 1px solid var(--line);
        }
        .chip {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 12px;
            white-space: nowrap;
            background: var(--sunk);
            color: var(--muted);
        }
        .chip.bad {
            background: var(--bad-soft);
            color: var(--bad);
        }
        .chip.warn {
            background: var(--warn-soft);
            color: var(--warn);
        }
        .method {
            display: inline-block;
            min-width: 46px;
            text-align: center;
            padding: 3px 6px;
            border-radius: 6px;
            font:
                700 11px ui-monospace,
                Menlo,
                monospace;
            background: var(--get-soft);
            color: var(--get);
        }
        .method.post {
            background: var(--post-soft);
            color: var(--post);
        }

        .topbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px 20px;
            padding: 14px 32px;
            background: var(--panel);
            border-bottom: 1px solid var(--line);
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--fg);
            font-weight: 700;
            font-size: 17px;
        }
        .brand:hover {
            color: var(--fg);
        }
        .crumbs {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            color: var(--muted);
        }
        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-left: auto;
        }
        .page {
            max-width: 1240px;
            margin: 0 auto;
            padding: 28px 32px 56px;
        }
        @media (max-width: 640px) {
            .topbar,
            .page {
                padding-inline: 16px;
            }
        }
    </style>
    @stack('head')
</head>
<body>
    <header class="topbar">
        <a href="/" class="brand">
            <svg width="26" height="26" viewBox="0 0 28 28" aria-hidden="true">
                <circle cx="12" cy="12" r="8" fill="none" stroke="var(--brass)" stroke-width="2.5"></circle>
                <path d="M18 18 L25 25" stroke="var(--brass)" stroke-width="2.5" stroke-linecap="round"></path>
                <path d="M8 10 h8 M8 13 h6" stroke="var(--muted)" stroke-width="1.6" stroke-linecap="round"></path>
            </svg>
            Monocle demos
        </a>
        @hasSection('crumbs')
            <nav class="crumbs" aria-label="Breadcrumb">@yield('crumbs')</nav>
        @endif
        <div class="themes" role="group" aria-label="Theme">
            <button type="button" data-theme-choice="system">Auto</button>
            <button type="button" data-theme-choice="light">Light</button>
            <button type="button" data-theme-choice="dark">Dark</button>
        </div>
        <div class="actions">
            <a class="button primary" href="/_monocle" target="_blank">Open dashboard</a>
            <a class="button" href="/demo/queue/work">
                Work the queue
                @if ($waiting)
                    <span class="chip warn">{{ $waiting }} waiting</span>
                @endif
            </a>
            <button type="button" class="button danger" id="clear-captures">Clear captures</button>
        </div>
    </header>

    <div class="page">
        @yield('content')
    </div>

    <script>
        // Theme switcher: the same setting as the dashboard's (Auto, Light, Dark).
        const themeButtons = document.querySelectorAll('[data-theme-choice]');

        function applyTheme(choice) {
            if (choice === 'system') {
                delete document.documentElement.dataset.theme;
            } else {
                document.documentElement.dataset.theme = choice;
            }

            themeButtons.forEach((button) =>
                button.setAttribute('aria-pressed', String(button.dataset.themeChoice === choice)),
            );
        }

        let storedTheme = 'system';
        try {
            storedTheme = localStorage.getItem('monocle.theme') ?? 'system';
        } catch {}
        applyTheme(storedTheme);

        themeButtons.forEach((button) =>
            button.addEventListener('click', () => {
                try {
                    localStorage.setItem('monocle.theme', button.dataset.themeChoice);
                } catch {}
                applyTheme(button.dataset.themeChoice);
            }),
        );

        // Clears Monocle's captures through the dashboard API, which needs the CSRF token.
        document.getElementById('clear-captures').addEventListener('click', async (event) => {
            const token = document.querySelector('meta[name="csrf-token"]').content;
            const response = await fetch('/_monocle/api/captures', {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' },
            });
            event.target.textContent = response.ok ? 'Cleared' : 'Could not clear';
        });
    </script>
    @stack('scripts')
</body>
</html>
