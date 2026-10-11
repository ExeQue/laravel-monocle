<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\Filament;

use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Pages\BasePage;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Contracts\HasTable;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * What a Livewire component is to Filament: the kind (resource page, page,
 * relation manager, widget, table or other Filament component), the panel,
 * the resource and the record it shows.
 *
 * @internal
 */
final class FilamentComponent
{
    /**
     * Nothing for a component Filament has no part in.
     *
     * @return array{filament?: string, panel?: string, resource?: string, record?: int|string, owner_record?: int|string}
     */
    public static function describe(object $component): array
    {
        $kind = self::kind($component);

        if ($kind === null) {
            return [];
        }

        return array_filter([
            'filament' => $kind,
            'panel' => self::panel(),
            'resource' => self::resource($component),
            'record' => method_exists($component, 'getRecord') ? self::key(fn (): mixed => $component->getRecord()) : null,
            'owner_record' => $component instanceof RelationManager ? self::key(fn (): mixed => $component->getOwnerRecord()) : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    public static function panel(): ?string
    {
        return Filament::getCurrentPanel()?->getId();
    }

    private static function kind(object $component): ?string
    {
        return match (true) {
            $component instanceof ResourcePage => 'resource page',
            $component instanceof BasePage => 'page',
            $component instanceof RelationManager => 'relation manager',
            $component instanceof Widget => 'widget',
            $component instanceof HasTable => 'table',
            $component instanceof HasActions, $component instanceof HasSchemas, str_starts_with($component::class, 'Filament\\') => 'component',
            default => null,
        };
    }

    private static function resource(object $component): ?string
    {
        if ($component instanceof ResourcePage) {
            return $component::getResource();
        }

        if (! $component instanceof RelationManager) {
            return null;
        }

        $page = $component->pageClass;

        return is_string($page) && is_subclass_of($page, ResourcePage::class) ? $page::getResource() : null;
    }

    /**
     * The key of the record a closure returns; null when there is none yet,
     * where asking for it aborts.
     */
    private static function key(callable $record): int|string|null
    {
        try {
            $model = $record();
        } catch (Throwable) {
            return null;
        }

        return $model instanceof Model ? $model->getKey() : null;
    }
}
