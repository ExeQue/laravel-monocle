<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Dashboard;

use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\LensRegistry;

/**
 * The dashboard tabs a capture gets, after Overview and Timeline: one per
 * section with rows, or with data and a tab view of its own. A section
 * another tab includes is shown there instead, as long as that tab is; on
 * its own, it carries the problems its host's lens reported.
 *
 * @internal
 */
final readonly class TabList
{
    public function __construct(
        private LensRegistry $registry,
    ) {
    }

    /**
     * @param  list<string>  $order  section keys in the order they come first
     * @param  array<string, array{warnings: int, errors: int}>  $problems
     * @return list<array{key: string, label: string|null, count: int|null, description: string|null, problems: int, errors: int}>
     */
    public function for(Capture $capture, array $order, array $problems): array
    {
        $presentations = $this->registry->presentations();
        $descriptions = $this->registry->descriptions();
        $hosts = $this->hostsBySection($presentations);

        $keys = $this->shownKeys($capture, $presentations);
        $keys = $this->withoutHosted($keys, $presentations);
        $keys = $this->ordered($keys, $order);

        $tabs = [];

        foreach ($keys as $key) {
            // A tab counts the problems of the sections it shows and of the tabs that would host it.
            $sections = [$key, ...$this->includes($presentations, $key), ...($hosts[$key] ?? [])];
            $count = $this->rowCount($capture, $key);

            $tabs[] = [
                'key' => $key,
                'label' => ($presentations[$key] ?? null)?->label,
                'count' => $count ?: null,
                'description' => $descriptions[$key] ?? null,
                'problems' => $this->problemCount($problems, $sections, 'warnings'),
                'errors' => $this->problemCount($problems, $sections, 'errors'),
            ];
        }

        return $tabs;
    }

    /**
     * Every section with rows, or with data and a tab view of its own.
     *
     * @param  array<string, Presentation>  $presentations
     * @return list<string>
     */
    private function shownKeys(Capture $capture, array $presentations): array
    {
        $sectionKeys = array_map('strval', array_keys($capture->sections));
        $keys = array_unique([...$sectionKeys, ...array_keys($presentations)]);
        $shown = [];

        foreach ($keys as $key) {
            if ($this->isShown($capture, $presentations, $key)) {
                $shown[] = $key;
            }
        }

        return $shown;
    }

    /**
     * @param  array<string, Presentation>  $presentations
     */
    private function isShown(Capture $capture, array $presentations, string $key): bool
    {
        if ($this->rowCount($capture, $key) > 0) {
            return true;
        }

        if (($presentations[$key] ?? null)?->tabView === null) {
            return false;
        }

        return ($capture->sections[$key] ?? []) !== [];
    }

    /**
     * Drops the sections a shown tab includes.
     *
     * @param  list<string>  $keys
     * @param  array<string, Presentation>  $presentations
     * @return list<string>
     */
    private function withoutHosted(array $keys, array $presentations): array
    {
        $hosted = [];

        foreach ($keys as $key) {
            foreach ($this->includes($presentations, $key) as $section) {
                $hosted[$section] = true;
            }
        }

        return array_values(array_filter($keys, fn (string $key): bool => ! isset($hosted[$key])));
    }

    /**
     * Keys listed in $order first, in that order; the rest keep their place after them.
     *
     * @param  list<string>  $keys
     * @param  list<string>  $order
     * @return list<string>
     */
    private function ordered(array $keys, array $order): array
    {
        $rank = [];
        $last = count($order);

        foreach ($order as $position => $key) {
            $rank[$key] ??= $position;
        }

        usort($keys, fn (string $a, string $b): int => ($rank[$a] ?? $last) <=> ($rank[$b] ?? $last));

        return $keys;
    }

    /**
     * Section => the tabs that include it, whether those tabs are shown or not.
     *
     * @param  array<string, Presentation>  $presentations
     * @return array<string, list<string>>
     */
    private function hostsBySection(array $presentations): array
    {
        $hosts = [];

        foreach ($presentations as $host => $presentation) {
            foreach ($presentation->includes as $section) {
                $hosts[$section][] = (string) $host;
            }
        }

        return $hosts;
    }

    /**
     * @param  array<string, Presentation>  $presentations
     * @return list<string>
     */
    private function includes(array $presentations, string $key): array
    {
        return ($presentations[$key] ?? null)?->includes ?? [];
    }

    private function rowCount(Capture $capture, string $key): int
    {
        $section = $capture->sections[$key] ?? null;

        if (! is_array($section)) {
            return 0;
        }

        return array_is_list($section) ? count($section) : 0;
    }

    /**
     * @param  array<string, array{warnings: int, errors: int}>  $problems
     * @param  list<string>  $sections
     * @param  'warnings'|'errors'  $kind
     */
    private function problemCount(array $problems, array $sections, string $kind): int
    {
        $total = 0;

        foreach ($sections as $section) {
            $total += $problems[$section][$kind] ?? 0;
        }

        return $total;
    }
}
