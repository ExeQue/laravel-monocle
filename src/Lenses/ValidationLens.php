<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use Closure;
use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Support\Normalizer;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ClosureValidationRule;
use Illuminate\Validation\Factory;
use Illuminate\Validation\InvokableValidationRule;
use Illuminate\Validation\Validator;
use ReflectionFunction;
use ReflectionProperty;
use Stringable;
use WeakMap;

/**
 * Every validation run: where it came from, the rules per field and, when it
 * failed, which rule failed for which field with the message. Values are
 * never recorded, only field names.
 */
final class ValidationLens implements Advises, Lens, Presentable, Resettable, Summarizable
{
    private ?Closure $resolver = null;

    /** @var WeakMap<Validator, float> validators of this capture and when they were made */
    private WeakMap $validators;

    public function __construct(
        private readonly Eyepiece $eyepiece,
        private readonly Application $app,
    ) {
        $this->validators = new WeakMap();
    }

    public function key(): string
    {
        return 'validation';
    }

    public function description(): string
    {
        return 'Validation runs: source (FormRequest class, Livewire component or null for inline validation), passed, the rules per field, failed (field, rule, parameters and message for each failure), input (the validated fields, masked like request input), fields and duration in ms.';
    }

    public function advice(): string
    {
        return 'Look here when a form or API call answers 422 or redirects back: which rule failed for which field, and where the validation came from.';
    }

    public function boot(Application $app): void
    {
        // After every provider has booted, so a resolver the application sets is kept,
        // and again whenever someone set their own resolver after that.
        $app->booted(fn () => $this->wrap($app));
        $this->eyepiece->listen(RouteMatched::class, fn () => $this->wrap($app));
        $this->eyepiece->listen(JobProcessing::class, fn () => $this->wrap($app));
    }

    /**
     * Put Monocle's resolver in front of the factory's current one, which it
     * calls to make the validator.
     */
    private function wrap(Application $app): void
    {
        $factory = $app->make('validator');

        if (! $factory instanceof Factory) {
            return;
        }

        $current = new ReflectionProperty(Factory::class, 'resolver')->getValue($factory);

        if ($this->isOurResolver($current)) {
            return;
        }

        $this->resolver = function (Translator $translator, array $data, array $rules, array $messages, array $attributes) use ($current): Validator {
            $validator = $current instanceof Closure
                ? $current($translator, $data, $rules, $messages, $attributes)
                : new Validator($translator, $data, $rules, $messages, $attributes);

            $this->watch($validator);

            return $validator;
        };

        $factory->resolver($this->resolver);
    }

    private function isOurResolver(mixed $resolver): bool
    {
        if ($resolver === null) {
            return false;
        }

        return $resolver === $this->resolver;
    }

    public function reset(): void
    {
        $this->validators = new WeakMap();
    }

    public function summarize(Capture $capture): Summary
    {
        $rows = $capture->rows('validation');

        if ($rows === []) {
            return Summary::make();
        }

        $failed = array_filter($rows, fn (array $row): bool => ! $row['passed']);

        return Summary::make()
            ->total('validations', count($rows), 'validations')
            ->total('validation_failed', count($failed), 'failed');
    }

    private function watch(Validator $validator): void
    {
        if (! $this->eyepiece->recording()) {
            return;
        }

        $this->validators[$validator] = $this->eyepiece->now();

        // The source is found now, while the FormRequest or component is still on the stack.
        $source = $this->source();

        $validator->after($this->eyepiece->wrap(function (Validator $validator) use ($source): void {
            // Validators made before this capture, or already recorded, are skipped.
            if (! isset($this->validators[$validator])) {
                return;
            }

            if (! $this->eyepiece->recording()) {
                return;
            }

            $started = $this->validators[$validator];
            unset($this->validators[$validator]);
            $failed = $this->failures($validator);
            $rules = $validator->getRules();

            $this->eyepiece->push('validation', [
                'source' => $source,
                'passed' => $failed === [],
                'rules' => array_map($this->rulesText(...), $rules),
                'failed' => $failed,
                'input' => $this->input($validator),
                'fields' => count($rules),
                'duration' => round($this->eyepiece->now() - $started, 3),
            ]);
        }, $this->key()));
    }

    /**
     * One entry per failed rule, paired with its message by position.
     *
     * @return list<array{field: array-key, rule: string, parameters: mixed, message: string|null}>
     */
    private function failures(Validator $validator): array
    {
        $failed = [];

        foreach ($validator->failed() as $field => $rules) {
            $messages = $validator->errors()->get($field);
            $index = 0;

            foreach ($rules as $rule => $parameters) {
                $failed[] = [
                    'field' => $field,
                    'rule' => $this->ruleName($rule),
                    'parameters' => Normalizer::normalize((array) $parameters),
                    'message' => $messages[$index] ?? $messages[0] ?? null,
                ];
                $index++;
            }
        }

        return $failed;
    }

    /**
     * A field's rules as one pipe-separated string, as they are usually written.
     *
     * @param  array<array-key, mixed>  $rules
     */
    private function rulesText(array $rules): string
    {
        $names = array_map($this->ruleName(...), $rules);

        return implode('|', $names);
    }

    /**
     * The validated input, limited to the fields with rules and masked like
     * request input. Uploaded files are described, not stored, and long
     * strings are cut at 1000 characters.
     *
     * @return array<array-key, mixed>
     */
    private function input(Validator $validator): array
    {
        // "items.*.name" validates the "items" input.
        $topLevel = array_map(fn (string $field): string => Str::before($field, '.'), array_keys($validator->getRules()));
        $validated = Arr::only($validator->getData(), array_unique($topLevel));

        $input = $this->eyepiece->masker(self::class)->inputs($validated);

        array_walk_recursive($input, function (mixed &$value): void {
            if ($value instanceof UploadedFile) {
                $value = [
                    'file' => $value->getClientOriginalName(),
                    'size' => $value->getSize(),
                    'mime' => $value->getClientMimeType(),
                ];
            } elseif (is_string($value)) {
                $value = Str::limit($value, 1000);
            }
        });

        return Normalizer::normalize($input);
    }

    /**
     * The FormRequest or Livewire component that validated, if any.
     */
    private function source(): ?string
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 40);

        foreach ($frames as $frame) {
            $object = $frame['object'] ?? null;

            if ($this->isSource($object)) {
                return $object::class;
            }
        }

        return null;
    }

    private function isSource(mixed $object): bool
    {
        if ($object instanceof FormRequest) {
            return true;
        }

        // Livewire is optional; the class_exists() guard keeps it from being autoloaded when it is missing.
        return class_exists('Livewire\\Component') && $object instanceof \Livewire\Component;
    }

    /**
     * A rule as text with its parameters: "max:255", "in:a,b", or a rule
     * object's class with its public properties.
     */
    private function ruleName(mixed $rule): string
    {
        if ($rule === ClosureValidationRule::class) {
            return 'Closure';
        }

        if (is_string($rule)) {
            return $rule;
        }

        // Rule objects are wrapped by the validator; record the rule the application wrote.
        if ($rule instanceof InvokableValidationRule) {
            return $this->ruleName($rule->invokable());
        }

        if ($rule instanceof ClosureValidationRule) {
            return $this->ruleName($rule->callback);
        }

        if ($rule instanceof Closure) {
            $function = new ReflectionFunction($rule);
            $file = Str::after((string) $function->getFileName(), $this->app->basePath().DIRECTORY_SEPARATOR);

            return 'Closure ('.$file.':'.$function->getStartLine().')';
        }

        if ($rule instanceof Stringable) {
            return (string) $rule;
        }

        if (is_object($rule)) {
            $properties = Normalizer::normalize(get_object_vars($rule));

            if ($properties === []) {
                return $rule::class;
            }

            return $rule::class.' '.json_encode($properties, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return (string) json_encode($rule);
    }

    public function presentation(): Presentation
    {
        return Presentation::table()
            ->tab('monocle::tabs.validation')
            ->detailsOnly(['input'])
            ->details('monocle::details.validation')
            ->format(['rules' => 'rules', 'passed' => ['flag', 'passed', 'failed', 'warn']]);
    }
}
