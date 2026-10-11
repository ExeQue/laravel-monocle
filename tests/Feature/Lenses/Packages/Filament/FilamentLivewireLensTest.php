<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Packages\Filament;

use Closure;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Packages\Filament\FilamentComponent;
use ExeQue\Monocle\Lenses\Packages\Filament\FilamentLivewireLens;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\EventBus;
use Livewire\Livewire;
use ReflectionFunction;
use Tests\Fixtures\Filament\BookNotesRelationManager;
use Tests\Fixtures\Filament\BookResource;
use Tests\Fixtures\Filament\EditBook;
use Tests\Fixtures\Filament\ListBooks;
use Tests\Fixtures\Filament\UnregisteredBookPage;
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
    it('describes itself', function (): void {
        expect(app(FilamentLivewireLens::class)->description())->toContain('filament');
    });

    it('leaves alone components it cannot describe or that render while nothing is recording', function (): void {
        $listener = collect((fn (): array => $this->listeners['dehydrate'])->call(app(EventBus::class)))
            ->first(fn (Closure $listener): bool => new ReflectionFunction($listener)->getClosureThis() instanceof FilamentLivewireLens);
        $unrecorded = new ListBooks();
        $unrecorded->setId('unrecorded');
        $unregistered = new UnregisteredBookPage();
        $unregistered->setId('unregistered');

        $listener($unrecorded);
        app(Eyepiece::class)->start(CaptureType::Request);
        $listener($unregistered);
        app(Eyepiece::class)->push('livewire', ['id' => 'unrecorded', 'class' => ListBooks::class]);
        app(Eyepiece::class)->push('livewire', ['id' => 'unregistered', 'class' => UnregisteredBookPage::class]);

        $rows = app(Eyepiece::class)->finalize()->rows('livewire');

        expect($rows[0])->not->toHaveKey('filament')
            ->and($rows[1])->not->toHaveKey('filament');
    });
});

describe(FilamentComponent::class, function (): void {
    beforeEach(function (): void {
        Schema::create('books', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
        });

        Filament::setCurrentPanel('admin');
    });

    it('describes a relation manager by the resource of its page and its owner record', function (): void {
        $book = Book::query()->create(['title' => 'Dune']);
        $manager = new BookNotesRelationManager();
        $manager->ownerRecord = $book;
        $manager->pageClass = EditBook::class;

        expect(FilamentComponent::describe($manager))->toBe([
            'filament' => 'relation manager',
            'panel' => 'admin',
            'resource' => BookResource::class,
            'owner_record' => $book->getKey(),
        ]);

        $manager->pageClass = null;

        expect(FilamentComponent::describe($manager))->not->toHaveKey('resource');
    });

    it('leaves out the record of a page that has none yet', function (): void {
        expect(FilamentComponent::describe(new EditBook()))->toBe([
            'filament' => 'resource page',
            'panel' => 'admin',
            'resource' => BookResource::class,
        ]);
    });
    it('describes a page outside any resource by its panel alone', function (): void {
        expect(FilamentComponent::describe(new Dashboard()))->toBe(['filament' => 'page', 'panel' => 'admin']);
    });
});
