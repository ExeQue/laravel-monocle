@extends('layout', ['title' => $title])

@section('content')
    <h1>{{ $title }}</h1>
    <p>{{ $description }}</p>
    <pre>{{ $method }} {{ $url }}
@foreach ($headers as $name => $value)
{{ $name }}: {{ $value }}
@endforeach
@isset($body)

{{ json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}
@endisset</pre>
    <button id="send" type="button">Send request</button>
    <pre id="response">No response yet.</pre>
    <script>
        document.getElementById('send').addEventListener('click', async () => {
            const response = await fetch(@json($url), {
                method: @json($method),
                headers: @json($headers),
                body: @json(isset($body) ? json_encode($body) : null),
            });
            document.getElementById('response').textContent = response.status + '\n\n' + (await response.text());
        });
    </script>
@endsection
