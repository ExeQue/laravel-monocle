<?php

declare(strict_types=1);

namespace Workbench\App\Filament;

use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * A table that shows all of its 600 records at once, without pagination.
 */
final class SensorReadings extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'sensor-readings';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => array_map(fn (int $i): array => ['id' => $i, 'sensor' => 'Sensor '.($i % 12 + 1), 'celsius' => 18 + $i % 7], range(1, 600)))
            ->columns([
                TextColumn::make('sensor'),
                TextColumn::make('celsius'),
            ])
            ->paginated(false);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }
}
