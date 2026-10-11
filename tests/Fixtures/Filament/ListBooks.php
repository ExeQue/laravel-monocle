<?php

declare(strict_types=1);

namespace Tests\Fixtures\Filament;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use RuntimeException;

final class ListBooks extends ListRecords
{
    protected static string $resource = BookResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('greet')
                ->action(fn () => Notification::make()->title('Hello')->success()->send()),
            Action::make('rename')
                ->schema([TextInput::make('name')->required()])
                ->action(fn (array $data) => Notification::make()->title('Renamed to '.$data['name'])->send()),
            Action::make('refuse')
                ->action(fn (Action $action) => $action->failure()),
            Action::make('pause')
                ->action(fn (Action $action) => $action->halt()),
            Action::make('explode')
                ->action(fn () => throw new RuntimeException('action exploded')),
        ];
    }
}
