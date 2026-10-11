<?php

declare(strict_types=1);

namespace Tests\Fixtures\Filament;

use Filament\Resources\RelationManagers\RelationManager;

final class BookNotesRelationManager extends RelationManager
{
    protected static string $relationship = 'notes';
}
