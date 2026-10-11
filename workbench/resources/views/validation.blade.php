@extends('layout', ['title' => 'Validation'])

@section('content')
    <h1>Validation</h1>
    <p>The form is pre-filled with values that fail the form request.</p>
    @if ($errors->any())
        <ul class="errors">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif
    <form method="POST" action="/demo/validation/form-request" style="display: grid; gap: 0.5rem; max-width: 20rem">
        @csrf
        <input name="name" value="{{ old('name', 'Al') }}" />
        <input name="email" value="{{ old('email', 'not-an-email') }}" />
        <input name="handle" value="{{ old('handle', 'Not A Slug') }}" />
        <textarea name="message">{{ old('message', 'Too short') }}</textarea>
        <button type="submit">Submit</button>
    </form>
@endsection
