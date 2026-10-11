<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Packages\Filament;

use ExeQue\Monocle\Lenses\Packages\Filament\FilamentComponent;
use ExeQue\Monocle\Lenses\Packages\Filament\FilamentLivewireLens;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Fixtures\Filament\BookResource;
use Tests\Fixtures\Filament\EditBook;
use Tests\Fixtures\Filament\ListBooks;
use Tests\Fixtures\Livewire\Counter;
use Tests\Fixtures\Models\Book;

covers(FilamentLivewireLens::class, FilamentComponent::class);

describe(FilamentLivewireLens::class, function (): void {
    beforeEach(function (): void {
        Schema::create('books', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
        });

        Filament::setCurrentPanel('admin');
    });

    $row = fn (string $class): array => collect(latestCapture()->rows('livewire'))->firstWhere('class', $class);

    it('adds the panel, resource and kind to the rows of Filament pages', function () use ($row): void {
        Livewire::test(ListBooks::class);

        expect($row(ListBooks::class))->toMatchArray([
            'filament' => 'resource page',
            'panel' => 'admin',
            'resource' => BookResource::class,
        ])
            ->and($row(ListBooks::class))->not->toHaveKey('record');
    });

    it('adds the record an edit page shows', function () use ($row): void {
        $book = Book::query()->create(['title' => 'Dune']);

        Livewire::test(EditBook::class, ['record' => $book->getKey()]);

        expect($row(EditBook::class))->toMatchArray(['filament' => 'resource page', 'record' => $book->getKey()]);
    });

    it('leaves other components alone', function () use ($row): void {
        Livewire::test(Counter::class);

        expect($row(Counter::class))->not->toHaveKeys(['filament', 'panel']);
    });
});
