<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Posts;

use Filament\Resources\Pages\EditRecord;

final class EditPost extends EditRecord
{
    protected static string $resource = PostResource::class;
}
