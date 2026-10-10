<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Facades;

use Closure;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\MonocleManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Span span(?string $name = null, ?string $key = null)
 * @method static mixed measure(string $name, Closure $callback, array $meta = [])
 * @method static Span mark(string $name, array $meta = [])
 * @method static MonocleManager meta(array $meta)
 * @method static MonocleManager label(string $label)
 * @method static Capture|null active()
 * @method static MonocleManager enabled(bool|Closure $enabled)
 * @method static bool isEnabled()
 * @method static MonocleManager register(string $lens, array|bool $options = true)
 * @method static MonocleManager disable(string $lens)
 * @method static MonocleManager enable(string $lens)
 * @method static array lenses()
 * @method static MonocleManager extendStore(string $driver, Closure $factory)
 * @method static Eyepiece eyepiece()
 * @method static MonocleManager when(mixed $value = null, callable $callback = null, callable $default = null)
 * @method static MonocleManager unless(mixed $value = null, callable $callback = null, callable $default = null)
 *
 * @see MonocleManager
 */
class Monocle extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MonocleManager::class;
    }
}
