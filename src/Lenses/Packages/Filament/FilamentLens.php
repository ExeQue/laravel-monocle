<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\Filament;

use Closure;
use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Configurable;
use ExeQue\Monocle\Contracts\Lenses\Finalizable;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Contracts\Lenses\RequiresPackages;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Support\Normalizer;
use ExeQue\Monocle\Support\ThrowableChain;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\Enums\ActionStatus;
use Filament\Actions\Events\ActionCalled;
use Filament\Actions\Events\ActionCalling;
use Filament\Facades\Filament;
use Filament\Notifications\BroadcastNotification;
use Filament\Notifications\DatabaseNotification;
use Filament\Pages\BasePage;
use Filament\Resources\Events\RecordCreated;
use Filament\Resources\Events\RecordUpdated;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Throwable;

/**
 * Filament's own tools: the actions run on a Filament component and how
 * they ended, the notifications sent, the state of every table rendered,
 * records created and updated by resource pages, and the panel, tenant,
 * page and resource the request was for.
 */
final class FilamentLens implements Advises, Configurable, Finalizable, Lens, Presentable, RequiresPackages, Resettable, Summarizable
{
    /** Session keys Filament keeps notifications under until they are shown. */
    private const array NOTIFICATION_KEYS = ['filament.notifications', 'filament.claimed_notifications'];

    private int $largeTableRecords = 500;

    /** @var list<array<string, mixed>> */
    private array $actions = [];

    /** @var list<int> Indexes of the actions being run, innermost last. */
    private array $running = [];

    /** @var array<string, array<string, mixed>> Component id => its table as last rendered. */
    private array $tables = [];

    /** @var array<string, true>|null Notifications already seen; null until the session has been looked at. */
    private ?array $notified = null;

    /** @var array<string, mixed>|null */
    private ?array $page = null;

    private bool $touched = false;

    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function key(): string
    {
        return 'filament';
    }

    public static function packages(): array
    {
        return ['filament/filament'];
    }

    public function description(): string
    {
        return 'filament/filament. Section filament has one row per thing Filament did, by kind: action (name, component, status: success, failed, halted, invalid, error, modal opened or stopped; arguments, record, errors, exception, notifications sent, duration), notification (title, status, channel: flash, database or broadcast; body, action that sent it, notifiable), table (component, filters, search, column_searches, sort, grouping, tab, page, per_page, records, total, deferred, large) and record (created or updated, the record, the resource page). Section filament_context has one row: panel, path, guard, tenant, page, resource and record.';
    }

    public function advice(): string
    {
        return 'Look here for Filament panels: which actions ran and how they ended, the notifications sent, and the filters, search, sort and page of every table.';
    }

    public function configure(array $options): void
    {
        $this->largeTableRecords = (int) ($options['large_table_records'] ?? $this->largeTableRecords);
    }

    public function boot(): void
    {
        if (! class_exists(Filament::class)) {
            return;
        }

        $this->hook('mount', fn (Component $component) => $this->touch($component));
        $this->hook('hydrate', fn (Component $component) => $this->touch($component));
        $this->hook('call', $this->call(...));
        $this->hook('render', $this->render(...));
        $this->hook('exception', $this->exception(...));

        // Filament fires these by class name with the action, or the record, data and page, as the payload.
        $this->eyepiece->listen(ActionCalling::class, $this->calling(...));
        $this->eyepiece->listen(ActionCalled::class, $this->called(...));
        $this->eyepiece->listen(RecordCreated::class, fn (mixed $record, mixed $data = null, mixed $page = null) => $this->saved('created', $record, $page));
        $this->eyepiece->listen(RecordUpdated::class, fn (mixed $record, mixed $data = null, mixed $page = null) => $this->saved('updated', $record, $page));
        $this->eyepiece->listen(NotificationSent::class, $this->sent(...));
    }

    /**
     * A Livewire call that mounts or runs a Filament action. The action stays
     * open while it runs, so the events it fires and the exception it throws
     * land on its row.
     *
     * @param  array<int, mixed>  $params
     */
    private function call(Component $component, string $method, array $params): ?callable
    {
        if (! $component instanceof HasActions) {
            return null;
        }

        if (! in_array($method, ['mountAction', 'callMountedAction'], true)) {
            return null;
        }

        if (! $this->eyepiece->recording()) {
            return null;
        }

        $mounted = $this->mounted($component, $method, $params);

        if (! is_string($mounted['name'] ?? null)) {
            return null;
        }

        $context = (array) ($mounted['context'] ?? []);
        $arguments = Normalizer::normalize((array) ($mounted['arguments'] ?? []));
        $index = count($this->actions);

        $this->actions[] = [
            'kind' => 'action',
            'name' => $mounted['name'],
            'component' => $component::class,
            'component_id' => $component->getId(),
            'status' => null,
            'record' => $context['recordKey'] ?? null,
            'table' => ($context['table'] ?? false) ? true : null,
            'bulk' => ($context['bulk'] ?? false) ? true : null,
            'arguments' => $arguments ?: null,
            'errors' => null,
            'exception' => null,
            'notifications' => null,
            'time' => $this->eyepiece->now(),
            'duration' => null,
        ];
        $this->running[] = $index;

        return function () use ($component, $method, $index): void {
            $this->stop($index);

            if ($this->actions[$index]['status'] !== null) {
                return;
            }

            // A mount that ends without running leaves the action on top of the stack while its modal shows.
            $topName = Arr::last((array) $component->mountedActions)['name'] ?? null;
            $opened = $method === 'mountAction' && $topName === $this->actions[$index]['name'];

            $this->actions[$index]['status'] = $opened ? 'modal opened' : 'stopped';
        };
    }

    /**
     * The action a call is about: mountAction names it in its parameters,
     * callMountedAction runs the one on top of the mounted stack with the
     * arguments it is given added.
     *
     * @param  array<int, mixed>  $params
     * @return array<string, mixed>
     */
    private function mounted(Component&HasActions $component, string $method, array $params): array
    {
        if ($method === 'mountAction') {
            return [
                'name' => $params[0] ?? null,
                'arguments' => $params[1] ?? [],
                'context' => $params[2] ?? [],
            ];
        }

        $top = (array) (Arr::last((array) $component->mountedActions) ?? []);
        $arguments = [...(array) ($top['arguments'] ?? []), ...(array) ($params[0] ?? [])];

        return [...$top, 'arguments' => $arguments];
    }

    /**
     * Filament fires ActionCalling with either the event or the action itself.
     */
    private function calling(mixed $event): void
    {
        $action = $event instanceof ActionCalling ? $event->getAction() : $event;

        $this->ran($action, null);
    }

    private function called(mixed $event): void
    {
        $action = $event instanceof ActionCalled ? $event->getAction() : $event;
        $failed = $action instanceof Action && $action->getStatus() === ActionStatus::Failure;

        $this->ran($action, $failed ? 'failed' : 'success');
    }

    /**
     * Records whether the innermost running action got as far as running
     * (halted until it says otherwise) and how it ended.
     */
    private function ran(mixed $action, ?string $status): void
    {
        $index = end($this->running);

        if ($index === false) {
            return;
        }

        if (! $action instanceof Action) {
            return;
        }

        if ($action->getName() !== $this->actions[$index]['name']) {
            return;
        }

        $this->actions[$index]['status'] = $status ?? 'halted';

        $record = $this->eyepiece->outside(fn () => $action->getRecord(withDefault: false));

        if ($record instanceof Model) {
            $this->actions[$index]['record'] = $record->getKey();
        }
    }

    private function exception(mixed $target, Throwable $exception): void
    {
        $index = end($this->running);

        if ($index === false) {
            return;
        }

        if (! $target instanceof Component) {
            return;
        }

        if ($target->getId() !== $this->actions[$index]['component_id']) {
            return;
        }

        if ($exception instanceof ValidationException) {
            $this->actions[$index]['status'] = 'invalid';
            $this->actions[$index]['errors'] = array_keys($exception->errors());

            return;
        }

        $this->actions[$index]['status'] = 'error';
        $this->actions[$index]['exception'] = ThrowableChain::describe($exception);
        $this->stop($index);
    }

    private function stop(int $index): void
    {
        if (! in_array($index, $this->running, true)) {
            return;
        }

        $this->running = array_values(array_diff($this->running, [$index]));
        $this->actions[$index]['duration'] ??= round($this->eyepiece->now() - $this->actions[$index]['time'], 3);
        $this->actions[$index]['notifications'] = $this->notifications($this->actions[$index]['name']) ?: null;
    }

    private function render(Component $component): ?callable
    {
        if (! $component instanceof HasTable) {
            return null;
        }

        if (! $this->eyepiece->recording()) {
            return null;
        }

        return function () use ($component): void {
            try {
                $this->tables[$component->getId()] = [...$this->table($component), 'time' => $this->eyepiece->now()];
            } catch (Throwable) {
                // A table that cannot describe itself is left out.
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function table(Component&HasTable $component): array
    {
        $table = $component->getTable();
        $model = $table->getModel();
        $paginated = $table->isPaginated();

        // The records the table rendered, read from the component's protected cache so they are not queried again.
        $records = (fn (): mixed => $this->cachedTableRecords)->call($component);
        $count = is_countable($records) ? count($records) : null;

        $sortColumn = $component->getTableSortColumn();
        $sort = $sortColumn === null ? null : $sortColumn.' '.$component->getTableSortDirection();

        $deferred = $records === null && $table->isLoadingDeferred();
        $large = ! $paginated && $count !== null && $count > $this->largeTableRecords;

        return [
            'kind' => 'table',
            'name' => $model === null ? null : class_basename($model),
            'component' => $component::class,
            'filters' => $this->filters((array) ($component->tableFilters ?? [])) ?: null,
            'search' => $component->getTableSearch(),
            'column_searches' => array_filter($component->getTableColumnSearches(), filled(...)) ?: null,
            'sort' => $sort,
            'grouping' => $component->tableGrouping,
            'tab' => property_exists($component, 'activeTab') ? $component->activeTab : null,
            'page' => $paginated ? $component->getTablePage() : null,
            'per_page' => $paginated ? $component->getTableRecordsPerPage() : null,
            'records' => $count,
            'total' => $records instanceof LengthAwarePaginator ? $records->total() : null,
            'deferred' => $deferred ? true : null,
            'large' => $large ? true : null,
        ];
    }

    /**
     * The filters that are set, without the empty states Filament keeps for
     * every other filter.
     *
     * @param  array<array-key, mixed>  $filters
     * @return array<array-key, mixed>
     */
    private function filters(array $filters): array
    {
        $active = [];

        foreach ($filters as $name => $state) {
            $set = array_filter((array) $state, fn (mixed $value): bool => filled($value) && $value !== false);

            if ($set !== []) {
                $active[$name] = count($set) === 1 ? Normalizer::value(reset($set)) : Normalizer::normalize($set);
            }
        }

        return $active;
    }

    private function saved(string $event, mixed $record, mixed $page): void
    {
        if ($record instanceof RecordCreated || $record instanceof RecordUpdated) {
            [$record, $page] = [$record->getRecord(), $record->getPage()];
        }

        if (! $record instanceof Model) {
            return;
        }

        if (! $this->eyepiece->recording()) {
            return;
        }

        $this->eyepiece->push('filament', [
            'kind' => 'record',
            'name' => $event,
            'component' => is_object($page) ? $page::class : null,
            'record' => $record::class.'#'.$record->getKey(),
        ], origin: false);
    }

    /**
     * A Filament notification sent to the database or broadcast. Both are
     * queued, so they show where they are delivered.
     */
    private function sent(NotificationSent $event): void
    {
        $notification = $event->notification;
        $isFilament = $notification instanceof DatabaseNotification || $notification instanceof BroadcastNotification;

        if (! $isFilament) {
            return;
        }

        if (! $this->eyepiece->recording()) {
            return;
        }

        $notifiable = $event->notifiable;
        $notifiableName = $notifiable instanceof Model
            ? $notifiable::class.'#'.$notifiable->getKey()
            : get_debug_type($notifiable);

        $this->eyepiece->push('filament', [
            ...$this->notification($notification->data, $event->channel),
            'notifiable' => $notifiableName,
        ], origin: false);
    }

    /**
     * Notifications flashed to the session since it was last looked at,
     * pushed as rows; the titles are returned.
     *
     * @return list<string>
     */
    private function notifications(?string $action = null): array
    {
        if (! $this->eyepiece->recording()) {
            return [];
        }

        $titles = [];

        foreach ($this->flashed() as $id => $notification) {
            if (isset($this->notified[$id])) {
                continue;
            }

            $this->notified[$id] = true;
            $titles[] = (string) ($notification['title'] ?? '');

            $this->eyepiece->push('filament', [
                ...$this->notification($notification, 'flash'),
                'action' => $action,
            ], origin: false);
        }

        return $titles;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function flashed(): array
    {
        try {
            if (! app()->bound('session')) {
                return [];
            }

            $flashed = [];

            foreach (self::NOTIFICATION_KEYS as $key) {
                foreach ((array) session($key, []) as $notification) {
                    if (is_array($notification) && isset($notification['id'])) {
                        $flashed[(string) $notification['id']] = $notification;
                    }
                }
            }

            return $flashed;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $notification
     * @return array<string, mixed>
     */
    private function notification(array $notification, string $channel): array
    {
        return [
            'kind' => 'notification',
            'name' => $notification['title'] ?? null,
            'status' => $notification['status'] ?? null,
            'channel' => $channel,
            'body' => $notification['body'] ?? null,
        ];
    }

    /**
     * The first time a Filament component shows up, the notifications already
     * in the session belong to an earlier request.
     */
    private function touch(Component $component): void
    {
        if (! $this->eyepiece->recording()) {
            return;
        }

        if (FilamentComponent::describe($component) === []) {
            return;
        }

        $this->touched = true;
        $this->notified ??= array_fill_keys(array_keys($this->flashed()), true);

        // The first Filament page to show up is the one the request was for.
        if ($this->page === null && $component instanceof BasePage) {
            $this->page = ['page' => $component::class, ...FilamentComponent::describe($component)];
        }
    }

    public function finalize(Capture $capture): void
    {
        if ($this->touched || Filament::isServing()) {
            // Without a component to mark the earlier ones, every notification in the session is this request's.
            $this->notified ??= [];
            $this->notifications();
        }

        foreach ($this->actions as $action) {
            unset($action['component_id']);

            $this->eyepiece->push('filament', $action, origin: false);
        }

        foreach ($this->tables as $table) {
            $this->eyepiece->push('filament', $table, origin: false);
        }

        // The facts go with the rows they explain; on their own they would be a tab of their own.
        if ($capture->rows('filament') !== []) {
            $this->context();
        }
    }

    private function context(): void
    {
        $panel = Filament::getCurrentPanel();
        $tenant = Filament::getTenant();

        $context = [
            'panel' => $panel?->getId(),
            'path' => $panel?->getPath(),
            'guard' => $panel?->getAuthGuard(),
            'tenant' => $tenant === null ? null : $tenant::class.'#'.$tenant->getKey(),
            'page' => $this->page['page'] ?? null,
            'resource' => $this->page['resource'] ?? null,
            'record' => $this->page['record'] ?? null,
        ];

        $this->eyepiece->push('filament_context', array_filter($context, fn (mixed $value): bool => $value !== null), origin: false);
    }

    public function reset(): void
    {
        $this->actions = [];
        $this->running = [];
        $this->tables = [];
        $this->notified = null;
        $this->page = null;
        $this->touched = false;
    }

    public function summarize(Capture $capture): Summary
    {
        $rows = $capture->rows('filament');

        if ($rows === []) {
            return Summary::make();
        }

        $errors = [];
        $warnings = [];

        foreach ($rows as $row) {
            $where = class_basename((string) ($row['component'] ?? ''));
            $exception = $row['exception'] ?? null;

            if ($exception !== null) {
                $errors[] = sprintf('Filament action "%s" on %s threw %s: %s', $row['name'], $where, $exception['class'], $exception['message']);
            }

            $isAction = ($row['kind'] ?? null) === 'action';

            if ($isAction && ($row['status'] ?? null) === 'failed') {
                $errors[] = sprintf('Filament action "%s" on %s failed.', $row['name'], $where);
            }

            if ($row['large'] ?? false) {
                $warnings[] = sprintf('Filament table on %s loaded %d records without pagination.', $where, $row['records']);
            }
        }

        $count = fn (string $kind): int => count(array_filter($rows, fn (array $row): bool => ($row['kind'] ?? null) === $kind));

        return Summary::make()
            ->total('filament_actions', $count('action'), 'actions')
            ->total('filament_notifications', $count('notification'), 'notifications')
            ->errors($errors)
            ->warnings($warnings);
    }

    public function presentation(): Presentation
    {
        return Presentation::table()
            ->tab('monocle::tabs.filament')
            ->includes(['filament_context'])
            ->columns(['kind', 'name', 'component', 'status', 'channel', 'record', 'search', 'filters', 'sort', 'records', 'total', 'duration'])
            ->format([
                'status' => ['highlight', ['failed', 'error', 'invalid', 'danger'], 'bad'],
                'filters' => 'fields',
            ])
            ->highlight(['exception' => 'bad', 'large' => 'warn'])
            ->groupBy(['kind' => 'by kind', 'component' => 'by component']);
    }

    private function hook(string $event, callable $callback): void
    {
        // Livewire calls a returned closure when the step finishes; it is Monocle's work too.
        app('livewire')->listen($event, $this->eyepiece->wrap(function (mixed ...$arguments) use ($callback): mixed {
            $finish = $callback(...$arguments);

            return $finish instanceof Closure ? $this->eyepiece->wrap($finish) : $finish;
        }));
    }
}
