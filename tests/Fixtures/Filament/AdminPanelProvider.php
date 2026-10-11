<?php

declare(strict_types=1);

namespace Tests\Fixtures\Filament;

use Filament\Panel;
use Filament\PanelProvider;

final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->resources([BookResource::class]);
    }
}
