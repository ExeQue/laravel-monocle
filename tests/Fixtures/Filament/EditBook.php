<?php

declare(strict_types=1);

namespace Tests\Fixtures\Filament;

use Filament\Resources\Pages\EditRecord;

final class EditBook extends EditRecord
{
    protected static string $resource = BookResource::class;
}
