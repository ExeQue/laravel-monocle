@extends('layout', ['title' => 'Livewire'])

@section('content')
    <h1>Livewire</h1>
    <p>Click the buttons or edit the label; every update is a request Monocle records.</p>
    <livewire:counter />
@endsection
