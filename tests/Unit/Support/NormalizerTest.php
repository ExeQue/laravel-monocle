<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use DateTimeImmutable;
use ExeQue\Monocle\Support\Normalizer;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;
use stdClass;
use Stringable;

covers(Normalizer::class);

enum NormalizerFixtureStatus: string
{
    case Active = 'active';
}

enum NormalizerFixturePlain
{
    case Yes;
}

describe(Normalizer::class, function (): void {
    it('turns models into Class#id and enums into values or names', function (): void {
        $model = new class () extends Model {
            protected $guarded = [];
        };
        $model->id = 7;

        $normalized = Normalizer::normalize(['model' => $model, 'status' => NormalizerFixtureStatus::Active, 'plain' => NormalizerFixturePlain::Yes]);

        expect($normalized['model'])->toEndWith('#7')
            ->and($normalized['status'])->toBe('active')
            ->and($normalized['plain'])->toBe('Yes');
    });

    it('replaces closures and resources with placeholders and keeps scalars', function (): void {
        $resource = fopen('php://memory', 'r');

        $normalized = Normalizer::normalize(['fn' => fn () => 1, 'res' => $resource, 'n' => 1, 'nested' => ['ok' => true], 'none' => null]);

        expect($normalized)->toBe(['fn' => '[closure]', 'res' => '[resource]', 'n' => 1, 'nested' => ['ok' => true], 'none' => null]);

        fclose($resource);
    });

    it('formats dates and unwraps arrayable, json serializable and stringable objects', function (): void {
        $arrayable = new class () implements Arrayable {
            public function toArray(): array
            {
                return ['from' => 'arrayable'];
            }
        };
        $json = new class () implements JsonSerializable {
            public function jsonSerialize(): array
            {
                return ['from' => 'json'];
            }
        };
        $jsonScalar = new class () implements JsonSerializable {
            public function jsonSerialize(): string
            {
                return 'scalar';
            }
        };
        $stringable = new class () implements Stringable {
            public function __toString(): string
            {
                return 'stringy';
            }
        };
        $plain = new stdClass();

        $normalized = Normalizer::normalize([
            'date' => new DateTimeImmutable('2026-01-02T03:04:05+00:00'),
            'arrayable' => $arrayable,
            'json' => $json,
            'json_scalar' => $jsonScalar,
            'stringable' => $stringable,
            'plain' => $plain,
        ]);

        expect($normalized['date'])->toBe('2026-01-02T03:04:05+00:00')
            ->and($normalized['arrayable'])->toBe(['from' => 'arrayable'])
            ->and($normalized['json'])->toBe(['from' => 'json'])
            ->and($normalized['json_scalar'])->toBe('scalar')
            ->and($normalized['stringable'])->toBe('stringy')
            ->and($normalized['plain'])->toBe('[stdClass]');
    });

    it('keeps 64 levels of nesting and cuts off what goes deeper', function (): void {
        $deep = ['l' => 1];

        for ($i = 0; $i < 70; $i++) {
            $deep = ['l' => $deep];
        }

        $cursor = Normalizer::normalize($deep);

        for ($i = 0; $i < 64; $i++) {
            $cursor = $cursor['l'];
        }

        expect($cursor)->toBe(['l' => '[nested]'])
            ->and(Normalizer::value('scalar'))->toBe('scalar');
    });

    it('marks objects that lead back to themselves as circular', function (): void {
        $loop = new class () implements \Illuminate\Contracts\Support\Arrayable {
            public ?object $other = null;

            public function toArray(): array
            {
                return ['self' => $this, 'other' => $this->other];
            }
        };
        $partner = new class () implements JsonSerializable {
            public ?object $back = null;

            public function jsonSerialize(): array
            {
                return ['back' => $this->back];
            }
        };
        $loop->other = $partner;
        $partner->back = $loop;

        $normalized = Normalizer::normalize(['x' => $loop, 'again' => $loop]);

        expect($normalized['x']['self'])->toStartWith('[circular ')
            ->and($normalized['x']['other']['back'])->toStartWith('[circular ')
            ->and($normalized['again'])->toBeArray();
    });

});
