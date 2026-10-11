<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\Filament;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\ExtendsLens;
use ExeQue\Monocle\Contracts\Lenses\RequiresPackages;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Packages\Livewire\LivewireLens;
use Livewire\Component;
use Throwable;

/**
 * Filament context on the livewire section: what each Filament component
 * is (resource page, page, relation manager, widget, table), its panel, its
 * resource and the record it shows.
 */
final class FilamentLivewireLens implements ExtendsLens, Lens, RequiresPackages, Resettable
{
    /** @var array<string, array<string, mixed>> Component id => its Filament context. */
    private array $components = [];

    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function key(): string
    {
        return 'livewire_filament';
    }

    public static function packages(): array
    {
        return ['filament/filament'];
    }

    public static function extends(): string
    {
        return LivewireLens::class;
    }

    public function description(): string
    {
        return 'With filament/filament: filament (resource page, page, relation manager, widget, table or component), panel, resource, record and owner_record (on a relation manager) on the rows of Filament components.';
    }

    public function boot(): void
    {
        app('livewire')->listen('dehydrate', function (Component $component): void {
            if (! $this->eyepiece->recording()) {
                return;
            }

            try {
                $this->components[$component->getId()] = FilamentComponent::describe($component);
            } catch (Throwable) {
                // A component that cannot be described keeps its row as it is.
            }
        });

        $this->eyepiece->modify('livewire', fn (array $row): array => [...$row, ...($this->components[$row['id'] ?? ''] ?? [])]);
    }

    public function reset(): void
    {
        $this->components = [];
    }
}
