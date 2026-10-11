<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ $title ?? 'Monocle demo' }}</title>
    <style>
        body {
            font:
                15px/1.5 system-ui,
                sans-serif;
            max-width: 60rem;
            margin: 2rem auto;
            padding: 0 1rem;
            color: #1f2933;
        }
        a {
            color: #2563eb;
        }
        h1 {
            margin-bottom: 0.25rem;
        }
        h2 {
            margin: 2rem 0 0.5rem;
            font-size: 1.1rem;
            border-bottom: 1px solid #e4e7eb;
            padding-bottom: 0.25rem;
        }
        ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        li {
            display: flex;
            gap: 1rem;
            align-items: baseline;
            padding: 0.3rem 0;
        }
        li > :first-child {
            flex: 0 0 15rem;
        }
        li span {
            color: #52606d;
        }
        form {
            display: inline;
            margin: 0;
        }
        button {
            font: inherit;
            color: #2563eb;
            background: none;
            border: 1px solid #cbd2d9;
            border-radius: 4px;
            padding: 0 0.5rem;
            cursor: pointer;
        }
        pre {
            background: #f5f7fa;
            padding: 1rem;
            border-radius: 6px;
            overflow: auto;
        }
        .bar {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .errors {
            color: #b91c1c;
        }
    </style>
</head>
<body>
    <div class="bar"><a href="/">All demos</a><a href="/_monocle" target="_blank">Open the Monocle dashboard</a></div>
    @yield('content')
</body>
</html>
