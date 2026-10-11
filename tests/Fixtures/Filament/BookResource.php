<?php

declare(strict_types=1);

namespace Tests\Fixtures\Filament;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Tests\Fixtures\Models\Book;

final class BookResource extends Resource
{
    protected static ?string $model = Book::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
            ])
            ->filters([
                Filter::make('long')->query(fn (Builder $query): Builder => $query->whereRaw('length(title) > 10')),
            ])
            ->recordActions([
                Action::make('shout')->action(fn (Book $record) => $record->update(['title' => strtoupper($record->title)])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBooks::route('/'),
            'edit' => EditBook::route('/{record}/edit'),
        ];
    }
}
