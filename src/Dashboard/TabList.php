<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Dashboard;

use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\LensRegistry;

/**
 * The dashboard tabs a capture gets, after Overview and Timeline: one per
 * section with rows, or with data and a tab view of its own. A section
 * another tab includes is shown there instead, as long as that tab is.
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
        $rows = fn (string $key): int => is_array($capture->sections[$key] ?? null) && array_is_list($capture->sections[$key]) ? count($capture->sections[$key]) : 0;
        $included = fn (string $key): array => ($presentations[$key] ?? null)?->includes ?? [];
        $shown = function (string $key) use ($capture, $presentations, $rows): bool {
            if ($rows($key) > 0) {
                return true;
            }

            return ($presentations[$key] ?? null)?->tabView !== null && ($capture->sections[$key] ?? []) !== [];
        };

        $keys = array_values(array_unique([...array_map('strval', array_keys($capture->sections)), ...array_keys($presentations)]));
        $keys = array_values(array_filter($keys, $shown));

        $hosted = [];

        foreach ($keys as $key) {
            foreach ($included($key) as $section) {
                $hosted[$section] = true;
            }
        }

        $keys = array_values(array_filter($keys, fn (string $key): bool => ! isset($hosted[$key])));
        $rank = fn (string $key): int => in_array($key, $order, true) ? (int) array_search($key, $order, true) : count($order);
        usort($keys, fn (string $a, string $b): int => $rank($a) <=> $rank($b));

        return array_map(function (string $key) use ($presentations, $descriptions, $rows, $included, $problems): array {
            $sections = [$key, ...$included($key)];
            $count = $rows($key);

            return [
                'key' => $key,
                'label' => ($presentations[$key] ?? null)?->label,
                'count' => $count ?: null,
                'description' => $descriptions[$key] ?? null,
                'problems' => array_sum(array_map(fn (string $section): int => $problems[$section]['warnings'] ?? 0, $sections)),
                'errors' => array_sum(array_map(fn (string $section): int => $problems[$section]['errors'] ?? 0, $sections)),
            ];
        }, $keys);
    }
}
