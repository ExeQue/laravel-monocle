<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

use ExeQue\Monocle\Lenses\ValidationLens;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Tests\Fixtures\RecordingValidator;
use Tests\Fixtures\Requests\StoreOrderRequest;

covers(ValidationLens::class);

describe(ValidationLens::class, function (): void {
    it('records the rules and every failed rule with its parameters, without the values', function (): void {
        Route::post('/signup', fn (Request $request) => $request->validate([
            'email' => 'required|email',
            'name' => 'max:3',
            'plan' => [Rule::in(['free', 'pro'])],
        ]));

        $this->postJson('/signup', ['name' => 'Rumpelstiltskin', 'plan' => 'gold'])->assertStatus(422);

        $capture = latestCapture();
        $row = $capture->rows('validation')[0];
        $failed = collect($row['failed'])->keyBy('field');

        expect($row['source'])->toBeNull()
            ->and($row['passed'])->toBeFalse()
            ->and($row['rules'])->toBe(['email' => 'required|email', 'name' => 'max:3', 'plan' => 'in:"free","pro"'])
            ->and($failed['email'])->toMatchArray(['rule' => 'Required', 'parameters' => []])
            ->and($failed['name'])->toMatchArray(['rule' => 'Max', 'parameters' => ['3']])
            ->and($failed['name']['message'])->toContain('3')
            ->and($row['fields'])->toBe(3)
            ->and(json_encode($row))->not->toContain('Rumpelstiltskin')
            ->and((new ValidationLens())->summarize($capture)['totals'])->toBe(['validations' => 1, 'validation_failed' => 1]);
    });

    it('names the FormRequest that validated and records passing validation', function (): void {
        Route::post('/orders', fn (StoreOrderRequest $request) => 'ok');

        $this->postJson('/orders', ['quantity' => 2])->assertOk();

        $row = latestCapture()->rows('validation')[0];

        expect($row['source'])->toBe(StoreOrderRequest::class)
            ->and($row['passed'])->toBeTrue()
            ->and($row['failed'])->toBe([])
            ->and($row['rules'])->toBe(['quantity' => 'required|integer|min:1']);
    });

    it('keeps a resolver the application set, also when it is set later', function (): void {
        RecordingValidator::$made = 0;
        Validator::resolver(function ($translator, $data, $rules, $messages, $attributes) {
            RecordingValidator::$made++;

            return new RecordingValidator($translator, $data, $rules, $messages, $attributes);
        });

        Route::post('/check', fn (Request $request) => $request->validate(['code' => 'required']) ? 'ok' : 'no');

        $this->postJson('/check', ['code' => 'x'])->assertOk();

        expect(RecordingValidator::$made)->toBe(1)
            ->and(latestCapture()->rows('validation'))->toHaveCount(1);
    });
});
