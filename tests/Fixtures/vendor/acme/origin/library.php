<?php

declare(strict_types=1);

/*
 * "Library" code for the Origin tests: the file sits in a vendor folder, so
 * Origin treats everything defined here as vendor code.
 */

use ExeQue\Monocle\Support\Origin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Traits\Macroable;

$basePath = dirname(__DIR__, 5);

$magic = new class ($basePath) {
    public function __construct(
        private readonly string $basePath
    ) {
    }

    public function __get(string $name): ?array
    {
        return Origin::capture($this->basePath, 3);
    }
};

$post = new class () extends Model {
    public static string $basePath = '';

    /** @var array<string, mixed>|null */
    public static ?array $origin = null;

    public function author(): never
    {
        self::$origin = Origin::capture(self::$basePath, 3);

        throw new RuntimeException('Origin captured.');
    }
};
$post::$basePath = $basePath;

$macroable = new class () {
    use Macroable;
};

$macroable::macro('first', function (): string {
    return 'first';
});

// The line between the two macros belongs to neither.

$macroable::macro('second', function (): string {
    return 'second';
});

$macroable::macro('invokable', new class () {
    public function __invoke(): string
    {
        return 'invokable';
    }
});

return ['magic' => $magic, 'post' => $post, 'macroable' => $macroable];
