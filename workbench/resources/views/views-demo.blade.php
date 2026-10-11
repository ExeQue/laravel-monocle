@extends('layout', ['title' => 'Views'])

@section('content')
    <h1>Views</h1>
    <p>A Blade component rendered {{ count($tags) }} times and a partial per post.</p>
    @foreach ($tags as $tag)
        <x-badge :label="$tag" />
    @endforeach
    @foreach ($posts as $post)
        @include('partials.post-summary', ['post' => $post])
    @endforeach
@endsection
