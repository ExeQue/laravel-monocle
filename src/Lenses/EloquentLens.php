<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Finalizable;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Contracts\Lenses\ProvidesSections;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Eyepiece;
use Illuminate\Database\Eloquent\Model;

/**
 * Counts model events per class and records every write with its changes.
 */
final class EloquentLens implements Advises, Finalizable, Lens, Presentable, ProvidesSections, Resettable, Summarizable
{
    private const array ACTIONS = ['retrieved', 'created', 'updated', 'deleted', 'restored', 'forceDeleted'];

    /** @var array<class-string, array<string, int>> */
    private array $counts = [];

    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function key(): string
    {
        return 'models';
    }

    public function sections(): array
    {
        return ['model_changes'];
    }

    public function description(): string
    {
        return 'Eloquent activity: one row per model class and action (retrieved, created, updated, deleted, restored, forceDeleted) with a count. Writes are also listed individually in the model_changes section with the model key, the connection and changes: one {field, from, to} per changed attribute (hidden attributes left out); rolled_back is true when the write happened in a transaction that was rolled back.';
    }

    public function advice(): string
    {
        return 'Look here to see how many models a request loaded and which attributes it changed, with old and new values.';
    }

    public function boot(): void
    {
        foreach (self::ACTIONS as $action) {
            $this->eyepiece->listen("eloquent.{$action}: *", function (string $event, array $payload) use ($action): void {
                $this->record($action, $payload[0] ?? null);
            });
        }
    }

    public function finalize(Capture $capture): void
    {
        $rows = [];

        foreach ($this->counts as $model => $actions) {
            foreach ($actions as $action => $count) {
                $rows[] = ['model' => $model, 'action' => $action, 'count' => $count];
            }
        }

        usort($rows, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        if ($rows !== []) {
            $capture->sections['models'] = $rows;
        }

        $this->markRolledBack($capture);
    }

    public function reset(): void
    {
        $this->counts = [];
    }

    public function summarize(Capture $capture): Summary
    {
        $counts = ['retrieved' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0];
        $top = [];

        foreach ($capture->rows('models') as $row) {
            $action = match ($row['action']) {
                'restored' => 'updated',
                'forceDeleted' => 'deleted',
                default => $row['action'],
            };
            $counts[$action] = ($counts[$action] ?? 0) + $row['count'];

            if ($action === 'retrieved') {
                $top[$row['model']] = ($top[$row['model']] ?? 0) + $row['count'];
            }
        }

        arsort($top);

        return Summary::make()
            ->totals([
                'models_top_retrieved' => array_slice($top, 0, 3, true),
            ])
            ->total('models_retrieved', $counts['retrieved'], 'retrieved')
            ->total('models_created', $counts['created'], 'created')
            ->total('models_updated', $counts['updated'], 'updated')
            ->total('models_deleted', $counts['deleted'], 'deleted');
    }

    private function record(string $action, mixed $model): void
    {
        if (! $model instanceof Model) {
            return;
        }

        if (! $this->eyepiece->recording()) {
            return;
        }

        $class = $model::class;
        $this->counts[$class][$action] = ($this->counts[$class][$action] ?? 0) + 1;

        // Reads are only counted: listing every loaded model would drown the writes.
        if ($action === 'retrieved') {
            return;
        }

        $this->eyepiece->push('model_changes', [
            'model' => $model::class,
            'key' => $model->getKey(),
            'action' => $action,
            'changes' => $this->changes($model, $action),
            'connection' => $model->getConnection()->getName(),
        ]);
    }

    /**
     * Writes made inside a transaction that was rolled back never reached the
     * database; flag them so the change list does not read as saved.
     */
    private function markRolledBack(Capture $capture): void
    {
        $rolledBack = array_filter($capture->spans, $this->isRolledBackTransaction(...));

        if ($rolledBack === []) {
            return;
        }

        if (! isset($capture->sections['model_changes'])) {
            return;
        }

        foreach ($capture->sections['model_changes'] as $index => $row) {
            foreach ($rolledBack as $span) {
                if ($this->happenedIn($row, $span)) {
                    $capture->sections['model_changes'][$index]['rolled_back'] = true;

                    break;
                }
            }
        }
    }

    private function isRolledBackTransaction(Span $span): bool
    {
        if ($span->kind !== 'db.transaction') {
            return false;
        }

        return ($span->meta['outcome'] ?? null) === 'rolled_back';
    }

    /**
     * Whether a model change was made on the span's connection while the span ran.
     *
     * @param  array<string, mixed>  $row
     */
    private function happenedIn(array $row, Span $span): bool
    {
        if (($span->meta['connection'] ?? null) !== ($row['connection'] ?? null)) {
            return false;
        }

        // A transaction still open at the end of the capture has no end yet.
        $end = $span->end ?? INF;

        return $row['time'] >= $span->start && $row['time'] <= $end;
    }

    /**
     * One entry per changed attribute with its value before and after. The
     * "updated" event fires before the model syncs its original values, so
     * getOriginal() still holds what was there before. Hidden attributes are
     * left out.
     *
     * @return list<array{field: string, from: mixed, to: mixed}>
     */
    private function changes(Model $model, string $action): array
    {
        $fields = match ($action) {
            'created' => array_map(fn (mixed $value): array => [null, $value], $model->getAttributes()),
            'deleted', 'forceDeleted' => array_map(fn (mixed $value): array => [$value, null], $model->getAttributes()),
            default => $this->beforeAndAfter($model),
        };

        $visible = array_diff_key($fields, array_flip($model->getHidden()));
        $changes = [];

        foreach ($visible as $field => [$from, $to]) {
            $changes[] = ['field' => (string) $field, 'from' => $from, 'to' => $to];
        }

        return $changes;
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function beforeAndAfter(Model $model): array
    {
        $fields = [];

        foreach ($model->getChanges() as $field => $value) {
            $fields[$field] = [$model->getRawOriginal($field), $value];
        }

        return $fields;
    }

    public function presentation(): Presentation
    {
        return Presentation::table()
            ->tab('monocle::tabs.models')
            ->includes([
                'model_changes' => Presentation::table()
                    ->label('Changes')
                    ->format(['rolled_back' => ['flag', 'rolled back', null, 'bad', 'warn']])
                    ->highlight(['rolled_back' => 'warn']),
            ]);
    }
}
