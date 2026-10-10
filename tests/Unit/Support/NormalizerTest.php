<?php

declare(strict_types=1);

use ExeQue\Monocle\Support\Normalizer;
use Illuminate\Database\Eloquent\Model;

covers(Normalizer::class);

enum NormalizerFixtureStatus: string
{
    case Active = 'active';
}

describe(Normalizer::class, function (): void {
    it('turns models into Class#id and enums into values', function (): void {
        $model = new class () extends Model {
            protected $guarded = [];
        };
        $model->id = 7;

        $normalized = Normalizer::normalize(['model' => $model, 'status' => NormalizerFixtureStatus::Active]);

        expect($normalized['model'])->toEndWith('#7')
            ->and($normalized['status'])->toBe('active');
    });

    it('replaces closures and resources with placeholders and keeps scalars', function (): void {
        $resource = fopen('php://memory', 'r');

        $normalized = Normalizer::normalize(['fn' => fn () => 1, 'res' => $resource, 'n' => 1, 'nested' => ['ok' => true]]);

        expect($normalized)->toBe(['fn' => '[closure]', 'res' => '[resource]', 'n' => 1, 'nested' => ['ok' => true]]);

        fclose($resource);
    });
});
