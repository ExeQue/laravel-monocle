<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use Closure;
use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Support\Normalizer;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Validation\Factory;
use Illuminate\Validation\Validator;
use ReflectionProperty;
use SplObjectStorage;
use Stringable;

/**
 * Every validation run: where it came from, the rules per field and, when it
 * failed, which rule failed for which field with the message. Values are
 * never recorded, only field names.
 */
final class ValidationLens implements Advises, Lens, Resettable, Summarizable
{
    private Eyepiece $eyepiece;

    private ?Closure $resolver = null;

    /** @var SplObjectStorage<Validator, float> validators of this capture and when they were made */
    private SplObjectStorage $validators;

    public function __construct()
    {
        $this->validators = new SplObjectStorage();
    }

    public function key(): string
    {
        return 'validation';
    }

    public function description(): string
    {
        return 'Validation runs: source (FormRequest class, Livewire component or null for inline validation), passed, the rules per field, failed (field, rule, parameters and message for each failure), fields and duration in ms. Values are never recorded.';
    }

    public function advice(): string
    {
        return 'Look here when a form or API call answers 422 or redirects back: which rule failed for which field, and where the validation came from.';
    }

    public function boot(Application $app, Eyepiece $eyepiece): void
    {
        $this->eyepiece = $eyepiece;

        // After every provider has booted, so a resolver the application sets is kept,
        // and again whenever someone set their own resolver after that.
        $app->booted(fn () => $this->wrap($app));
        $eyepiece->listen(RouteMatched::class, fn () => $this->wrap($app));
        $eyepiece->listen(JobProcessing::class, fn () => $this->wrap($app));
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

        $current = (new ReflectionProperty(Factory::class, 'resolver'))->getValue($factory);

        if ($current !== null && $current === $this->resolver) {
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

    public function reset(): void
    {
        $this->validators = new SplObjectStorage();
    }

    public function summarize(Capture $capture): array
    {
        $rows = $capture->rows('validation');

        if ($rows === []) {
            return [];
        }

        return [
            'totals' => [
                'validations' => count($rows),
                'validation_failed' => count(array_filter($rows, fn (array $row): bool => ! $row['passed'])),
            ],
            'warnings' => [],
        ];
    }

    private function watch(Validator $validator): void
    {
        if (! $this->eyepiece->recording()) {
            return;
        }

        $this->validators[$validator] = $this->eyepiece->now();
        $source = $this->source();

        $validator->after(function (Validator $validator) use ($source): void {
            if (! $this->validators->contains($validator) || ! $this->eyepiece->recording()) {
                return;
            }

            $started = $this->validators[$validator];
            $this->validators->detach($validator);
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

            $this->eyepiece->push('validation', [
                'source' => $source,
                'passed' => $failed === [],
                'rules' => array_map(fn (array $rules): string => implode('|', array_map($this->ruleName(...), $rules)), $validator->getRules()),
                'failed' => $failed,
                'fields' => count($validator->getRules()),
                'duration' => round($this->eyepiece->now() - $started, 3),
            ]);
        });
    }

    /**
     * The FormRequest or Livewire component that validated, if any.
     */
    private function source(): ?string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
            $object = $frame['object'] ?? null;

            if ($object instanceof FormRequest || (class_exists('Livewire\\Component') && $object instanceof \Livewire\Component)) {
                return $object::class;
            }
        }

        return null;
    }

    /**
     * A rule as text with its parameters: "max:255", "in:a,b", or a rule
     * object's class with its public properties.
     */
    private function ruleName(mixed $rule): string
    {
        if (is_string($rule)) {
            return $rule;
        }

        if ($rule instanceof Stringable) {
            return (string) $rule;
        }

        if (is_object($rule)) {
            $properties = Normalizer::normalize(get_object_vars($rule));

            return $rule::class.($properties === [] ? '' : ' '.json_encode($properties, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return (string) json_encode($rule);
    }
}
