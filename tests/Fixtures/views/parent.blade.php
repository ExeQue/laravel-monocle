<h1>{{ $title }}</h1>
@foreach ($items as $item)
    @include('partial', ['item' => $item])
@endforeach
