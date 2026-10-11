<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Posts;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use RuntimeException;

final class ListPosts extends ListRecords
{
    protected static string $resource = PostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('announce')
                ->schema([TextInput::make('message')->required()])
                ->action(fn (array $data) => Notification::make()->title('Announced')->body($data['message'])->success()->send()),
            Action::make('refuse')
                ->color('danger')
                ->action(function (Action $action): void {
                    Notification::make()->title('Refused')->danger()->send();
                    $action->failure();
                }),
            Action::make('explode')
                ->color('danger')
                ->action(fn () => throw new RuntimeException('The explode action threw.')),
        ];
    }
}
