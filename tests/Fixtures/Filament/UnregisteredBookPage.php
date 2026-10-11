<?php

declare(strict_types=1);

namespace Tests\Fixtures\Filament;

use Filament\Resources\Pages\ListRecords;

/**
 * A resource page that names no resource, so asking for it throws.
 */
final class UnregisteredBookPage extends ListRecords
{
}
