<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Finalizable;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Eyepiece;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;

/**
 * Counts model events per class and records every write with its changes.
 */
final class EloquentLens implements Finalizable, Lens, Resettable, Summarizable
{
    private const array ACTIONS = ['retrieved', 'created', 'updated', 'deleted', 'restored', 'forceDeleted'];

    private Eyepiece $eyepiece;

    /** @var array<class-string, array<string, int>> */
    private array $counts = [];

    public function key(): string
    {
        return 'models';
    }

    public function description(): string
    {
        return 'Eloquent activity: one row per model class and action (retrieved, created, updated, deleted, restored, forceDeleted) with a count. Writes are also listed individually in the model_changes section with the model key and changes: one {field, from, to} per changed attribute (hidden attributes left out).';
    }

    public function boot(Application $app, Eyepiece $eyepiece): void
    {
        $this->eyepiece = $eyepiece;

        foreach (self::ACTIONS as $action) {
            $eyepiece->listen("eloquent.{$action}: *", function (string $event, array $payload) use ($action): void {
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
    }

    public function reset(): void
    {
        $this->counts = [];
    }

    public function summarize(Capture $capture): array
    {
        $retrieved = 0;
        $written = 0;
        $top = [];

        foreach ($capture->rows('models') as $row) {
            if ($row['action'] === 'retrieved') {
                $retrieved += $row['count'];
                $top[$row['model']] = ($top[$row['model']] ?? 0) + $row['count'];
            } else {
                $written += $row['count'];
            }
        }

        arsort($top);

        return [
            'totals' => [
                'models_retrieved' => $retrieved,
                'models_written' => $written,
                'models_top_retrieved' => array_slice($top, 0, 3, true),
            ],
        ];
    }

    private function record(string $action, mixed $model): void
    {
        if (! $model instanceof Model || ! $this->eyepiece->recording()) {
            return;
        }

        $this->counts[$model::class][$action] = ($this->counts[$model::class][$action] ?? 0) + 1;

        if ($action === 'retrieved') {
            return;
        }

        $this->eyepiece->push('model_changes', [
            'model' => $model::class,
            'key' => $model->getKey(),
            'action' => $action,
            'changes' => $this->changes($model, $action),
        ]);
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

        $changes = [];

        foreach (array_diff_key($fields, array_flip($model->getHidden())) as $field => [$from, $to]) {
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
            $fields[$field] = [$model->getOriginal($field), $value];
        }

        return $fields;
    }
}
