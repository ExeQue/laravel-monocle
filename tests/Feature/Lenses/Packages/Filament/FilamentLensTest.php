<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Packages\Filament;

use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Packages\Filament\FilamentLens;
use ExeQue\Monocle\LensRegistry;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use RuntimeException;
use Tests\Fixtures\Filament\BookResource;
use Tests\Fixtures\Filament\EditBook;
use Tests\Fixtures\Filament\ListBooks;
use Tests\Fixtures\Models\Book;
use Tests\Fixtures\Models\User;

covers(FilamentLens::class);

describe(FilamentLens::class, function (): void {
    beforeEach(function (): void {
        Schema::create('books', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        $this->dune = Book::query()->create(['title' => 'Dune']);
        Book::query()->create(['title' => 'The Left Hand of Darkness']);

        $this->user = User::query()->create(['name' => 'Ada']);
        $this->actingAs($this->user);
        Filament::setCurrentPanel('admin');
    });

    $lens = fn (): FilamentLens => collect(app(LensRegistry::class)->booted())->first(fn (object $lens): bool => $lens instanceof FilamentLens);
    $rows = fn (string $kind): array => array_values(array_filter(latestCapture()->rows('filament'), fn (array $row): bool => $row['kind'] === $kind));

    it('records the panel, page and resource and the table a page renders', function () use ($rows): void {
        Livewire::test(ListBooks::class)->assertSee('Dune');

        expect(latestCapture()->rows('filament_context')[0])->toMatchArray([
            'panel' => 'admin',
            'path' => 'admin',
            'guard' => 'web',
            'page' => ListBooks::class,
            'resource' => BookResource::class,
        ])
            ->and($rows('action'))->toBe([])
            ->and($rows('table')[0])->toMatchArray([
                'name' => 'Book',
                'component' => ListBooks::class,
                'filters' => null,
                'search' => null,
                'sort' => null,
                'page' => 1,
                'per_page' => 10,
                'records' => 2,
                'total' => 2,
            ]);
    });

    it('records the search, sort and filters of a table', function () use ($rows): void {
        Livewire::test(ListBooks::class)
            ->searchTable('Dune')
            ->sortTable('title', 'desc')
            ->filterTable('long');

        expect($rows('table')[0])->toMatchArray([
            'search' => 'Dune',
            'sort' => 'title desc',
            'filters' => ['long' => true],
            'records' => 0,
            'total' => 0,
        ]);
    });

    it('records an action that ran and the notification it sent', function () use ($rows, $lens): void {
        Livewire::test(ListBooks::class)->callAction('greet');

        $action = $rows('action')[0];

        expect($action)->toMatchArray([
            'name' => 'greet',
            'component' => ListBooks::class,
            'status' => 'success',
            'notifications' => ['Hello'],
        ])
            ->and($action['duration'])->toBeNumeric()
            ->and($rows('notification'))->toHaveCount(1)
            ->and($rows('notification')[0])->toMatchArray(['name' => 'Hello', 'status' => 'success', 'channel' => 'flash', 'action' => 'greet'])
            ->and($lens()->summarize(latestCapture())->totals)->toMatchArray(['filament_actions' => 1, 'filament_notifications' => 1]);
    });

    it('records an action whose modal opened and then failed validation', function () use ($rows): void {
        $page = Livewire::test(ListBooks::class)->mountAction('rename');

        expect($rows('action')[0])->toMatchArray(['name' => 'rename', 'status' => 'modal opened']);

        $page->callMountedAction();

        expect($rows('action')[0]['status'])->toBe('invalid')
            ->and($rows('action')[0]['errors'])->toBe(['mountedActions.0.data.name']);

        $page->setActionData(['name' => 'Arrakis'])->callMountedAction();

        expect($rows('action')[0]['status'])->toBe('success')
            ->and($rows('notification')[0]['name'])->toBe('Renamed to Arrakis');
    });

    it('records actions that failed or halted, failures as errors', function () use ($rows, $lens): void {
        $page = Livewire::test(ListBooks::class)->callAction('refuse');

        expect($rows('action')[0]['status'])->toBe('failed')
            ->and($lens()->summarize(latestCapture())->errors)->toBe(['Filament action "refuse" on ListBooks failed.']);

        $page->callAction('pause');

        expect($rows('action')[0]['status'])->toBe('halted')
            ->and($lens()->summarize(latestCapture())->errors)->toBe([]);
    });

    it('records an action that threw as an error', function () use ($lens): void {
        $page = Livewire::test(ListBooks::class);

        try {
            $page->callAction('explode');
        } catch (RuntimeException) {
        }

        // The exception escaped before the kernel terminated, so the capture is still open.
        $capture = app(Eyepiece::class)->finalize() ?? latestCapture();
        $action = collect($capture->rows('filament'))->firstWhere('kind', 'action');

        expect($action['status'])->toBe('error')
            ->and($action['exception']['message'])->toBe('action exploded')
            ->and($lens()->summarize($capture)->errors)->toBe(['Filament action "explode" on ListBooks threw RuntimeException: action exploded']);
    });

    it('records a table action with its record', function () use ($rows): void {
        Livewire::test(ListBooks::class)->callTableAction('shout', $this->dune);

        expect($rows('action')[0])->toMatchArray(['name' => 'shout', 'status' => 'success', 'table' => true, 'record' => $this->dune->getKey()])
            ->and($this->dune->refresh()->title)->toBe('DUNE');
    });

    it('records the record an edit page saves', function () use ($rows): void {
        Livewire::test(EditBook::class, ['record' => $this->dune->getKey()])
            ->fillForm(['title' => 'Dune Messiah'])
            ->call('save');

        expect($rows('record')[0])->toMatchArray(['name' => 'updated', 'component' => EditBook::class, 'record' => Book::class.'#'.$this->dune->getKey()])
            ->and(latestCapture()->rows('filament_context')[0])->toMatchArray(['page' => EditBook::class, 'record' => $this->dune->getKey()]);
    });

    it('records notifications sent to the database', function (): void {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        app(Eyepiece::class)->start(CaptureType::Request);
        Notification::make()->title('Export ready')->warning()->sendToDatabase($this->user);
        $row = app(Eyepiece::class)->finalize()->rows('filament')[0];

        expect($row)->toMatchArray([
            'kind' => 'notification',
            'name' => 'Export ready',
            'status' => 'warning',
            'channel' => 'database',
            'notifiable' => User::class.'#'.$this->user->getKey(),
        ]);
    });

    it('warns about a table that loads many records without pagination', function () use ($lens): void {
        $lens()->configure(['large_table_records' => 1]);

        app(Eyepiece::class)->start(CaptureType::Request);
        app(Eyepiece::class)->push('filament', ['kind' => 'table', 'component' => ListBooks::class, 'records' => 2, 'large' => true]);

        expect($lens()->summarize(app(Eyepiece::class)->finalize())->warnings)->toBe(['Filament table on ListBooks loaded 2 records without pagination.']);
    });

    it('records nothing outside Filament', function () use ($lens): void {
        $this->get('/nothing-here');

        expect(latestCapture()->rows('filament_context'))->toBe([])
            ->and($lens()->summarize(latestCapture()))->toEqual(Summary::make());
    });
});
