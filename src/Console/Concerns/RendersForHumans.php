<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Console\Concerns;

use ExeQue\Monocle\Dashboard\Format;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

/**
 * Shared pieces for rendering command output for people with Laravel Prompts.
 *
 * @internal
 */
trait RendersForHumans
{
    protected function ms(mixed $ms): string
    {
        return Format::ms($ms);
    }

    protected function bytes(mixed $bytes): string
    {
        return Format::bytes($bytes);
    }

    protected function ago(?string $datetime): string
    {
        return $datetime === null ? '' : Carbon::parse($datetime)->diffForHumans(short: true);
    }

    /**
     * Any value as one short line for a table cell.
     */
    protected function cell(mixed $value, int $width = 60): string
    {
        return Str::limit(preg_replace('/\s+/', ' ', Format::value($value)) ?? '', $width);
    }

    /**
     * Rows as a table; columns default to the keys of the first row.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>|list<string>|null  $columns  keys, or key => header
     */
    protected function rowsTable(array $rows, ?array $columns = null, int $width = 60): void
    {
        if ($rows === []) {
            note('No rows.');

            return;
        }

        $columns ??= array_keys($rows[0]);
        $columns = array_is_list($columns) ? array_combine($columns, array_map(fn (string $key): string => Str::headline($key), $columns)) : $columns;

        table(
            array_values($columns),
            array_map(fn (array $row): array => array_map(fn (string $key): string => $this->cell($row[$key] ?? null, $width), array_keys($columns)), $rows),
        );
    }

    /**
     * Nested data as a two-column table of dot.notated keys and values,
     * the way the dashboard shows input and payloads.
     */
    protected function dotTable(mixed $value, string $key = 'Key', string $header = 'Value', int $width = 80): void
    {
        $pairs = Format::dot($value);

        if ($pairs === []) {
            note('Empty.');

            return;
        }

        table([$key, $header], array_map(fn (array $pair): array => [$pair[0], $this->cell($pair[1], $width)], $pairs));
    }

    /**
     * "Showing 1-50 of 120" and how to get the next page, from a paged result.
     *
     * @param  array<string, mixed>  $data
     */
    protected function paging(array $data): void
    {
        $offset = (int) ($data['offset'] ?? 0);
        $returned = (int) ($data['returned'] ?? 0);
        $total = (int) ($data['total'] ?? 0);

        if ($returned === 0 || ($offset === 0 && $returned >= $total)) {
            return;
        }

        $line = 'Showing '.($offset + 1).'-'.($offset + $returned).' of '.$total.'.';

        if (($data['next_offset'] ?? null) !== null) {
            $line .= ' Next page: --offset='.$data['next_offset'];
        }

        note($line);
    }

    /**
     * @param  list<string>  $warnings
     * @param  list<string>  $errors
     */
    protected function flags(array $warnings, array $errors): void
    {
        foreach ($errors as $message) {
            error($message);
        }

        foreach ($warnings as $message) {
            warning($message);
        }
    }
}
