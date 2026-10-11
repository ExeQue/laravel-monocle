@extends('layout', ['title' => 'Livewire exception'])

@section('content')
    <h1>Livewire exception</h1>
    <p>The component below threw before rendering and handled it; the capture still names the exception.</p>
    <livewire:broken-widget />
@endsection
