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

use Symfony\Component\Console\Formatter\OutputFormatter;

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
     * Any value as one short line for a table cell, escaped so text like
     * "<fg=red>" or SQL is shown as is instead of read as a style tag.
     */
    protected function cell(mixed $value, int $width = 60): string
    {
        $oneLine = preg_replace('/\s+/', ' ', Format::value($value)) ?? '';

        return OutputFormatter::escape(Str::limit($oneLine, $width));
    }

    /**
     * A number formatted by the name it is stored under: durations
     * (duration, time, start, *_ms) as ms, *_bytes as bytes, *_mb as MB.
     */
    protected function amount(string $name, mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (! is_int($value) && ! is_float($value)) {
            return $this->cell($value);
        }

        if ($this->isDuration($name)) {
            return $this->ms($value);
        }

        if (str_ends_with($name, '_bytes')) {
            return $this->bytes($value);
        }

        if (str_ends_with($name, '_mb')) {
            return $value.' MB';
        }

        return (string) $value;
    }

    private function isDuration(string $name): bool
    {
        if (in_array($name, ['duration', 'time', 'start'], true)) {
            return true;
        }

        return str_ends_with($name, '_ms');
    }

    /**
     * A row with its numbers formatted by their names; other values untouched.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function readable(array $row): array
    {
        foreach ($row as $key => $value) {
            if (is_int($value) || is_float($value)) {
                $row[$key] = $this->amount((string) $key, $value);
            }
        }

        return $row;
    }

    /**
     * An origin ("file:line via Class->method") relative to the project,
     * with the namespace cut from the class.
     */
    protected function shortOrigin(?string $origin): string
    {
        if ($origin === null) {
            return '';
        }

        foreach ([base_path(), (string) getcwd()] as $root) {
            $origin = Str::after($origin, rtrim($root, '/').'/');
        }

        return (string) preg_replace('/ via (?:\w+\\\\)+/', ' via ', $origin);
    }

    /**
     * A capture that failed: a failed job or command, or a 5xx response.
     *
     * @param  array<string, mixed>  $capture
     */
    protected function failed(array $capture): bool
    {
        $status = (string) ($capture['status'] ?? '');

        if ($status === 'failed') {
            return true;
        }

        if (! is_numeric($status)) {
            return false;
        }

        return (int) $status >= 500;
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

        $headers = $this->headers($columns ?? array_keys($rows[0]));
        $keys = array_keys($headers);

        $cells = collect($rows)->map(fn (array $row): array => array_map(
            fn (int|string $key): string => $this->cell($row[$key] ?? null, $width),
            $keys,
        ));

        table(array_values($headers), $cells->all());
    }

    /**
     * Column key => header; bare keys get their headline as header.
     *
     * @param  array<string, string>|list<string>  $columns
     * @return array<string, string>
     */
    private function headers(array $columns): array
    {
        if (! array_is_list($columns)) {
            return $columns;
        }

        return collect($columns)->mapWithKeys(fn (string $key): array => [$key => Str::headline($key)])->all();
    }

    /**
     * The columns worth a table: plain values (or lists of them) set in at
     * least one row, without noisy ones such as SQL shapes, bindings and
     * origins, at most $max of them, always keeping the duration.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: list<string>, 1: list<string>} the columns shown and the ones left out
     */
    protected function columns(array $rows, int $max = 8): array
    {
        $noisy = ['id', 'origin', 'shape', 'shape_hash', 'sql_with_bindings', 'bindings', 'meta'];

        $useful = collect(array_merge(...$rows))->keys()
            ->reject(fn (int|string $key): bool => in_array($key, $noisy, true))
            ->filter(function (int|string $key) use ($rows): bool {
                $values = array_column($rows, $key);

                return ! $this->holdsNestedData($values) && ! $this->allEmpty($values);
            })
            ->map(fn (int|string $key): string => (string) $key)
            ->values()
            ->all();

        $shown = array_slice($useful, 0, $max);

        // The duration is what people look for first, so it takes the last slot when it was cut.
        if (in_array('duration', $useful, true) && ! in_array('duration', $shown, true)) {
            $shown[$max - 1] = 'duration';
        }

        return [$shown, array_values(array_diff($useful, $shown))];
    }

    /**
     * A column with an array that holds more than plain values does not fit a cell.
     *
     * @param  list<mixed>  $values
     */
    private function holdsNestedData(array $values): bool
    {
        return collect($values)->contains(
            fn (mixed $value): bool => is_array($value) && array_filter($value, is_scalar(...)) !== $value,
        );
    }

    /**
     * @param  list<mixed>  $values
     */
    private function allEmpty(array $values): bool
    {
        return collect($values)->every(fn (mixed $value): bool => $value === null || $value === []);
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

        $rows = collect($pairs)->mapSpread(fn (string $dotKey, mixed $item): array => [$this->cell($dotKey, 50), $this->cell($item, $width)]);

        table([$key, $header], $rows->all());
    }

    /**
     * "Showing 1-50 of 120" and how to get the next page, from a paged result.
     *
     * @param  array<string, mixed>  $data
     * @param  string|null  $command  the command the --offset belongs to, when it is not this one
     */
    protected function paging(array $data, ?string $command = null): void
    {
        $offset = (int) ($data['offset'] ?? 0);
        $returned = (int) ($data['returned'] ?? 0);
        $total = (int) ($data['total'] ?? 0);

        if ($returned === 0) {
            return;
        }

        $everythingShown = $offset === 0 && $returned >= $total;

        if ($everythingShown) {
            return;
        }

        $line = 'Showing '.($offset + 1).'-'.($offset + $returned).' of '.$total.'.';
        $nextOffset = $data['next_offset'] ?? null;

        if ($nextOffset !== null) {
            $prefix = $command === null ? '' : $command.' ';
            $line .= ' Next page: '.$prefix.'--offset='.$nextOffset;
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
