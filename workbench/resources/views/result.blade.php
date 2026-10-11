@extends('layout', ['title' => $title])

@section('content')
    <h1>{{ $title }}</h1>
    <p>Done. Open the dashboard to see what this request recorded.</p>
    @isset($output)
        <pre>{{ is_string($output) ? $output : json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    @endisset
@endsection
