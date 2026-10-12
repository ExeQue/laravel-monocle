<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Contracts\Lenses;

interface ProvidesSections
{
    /**
     * The sections the lens writes besides its own key(), such as the Artisan
     * commands a capture called next to a command capture's own facts. Listing
     * the lens in monocle.dashboard.tabs places these right after its own tab.
     *
     * @return list<string>
     */
    public function sections(): array;
}
