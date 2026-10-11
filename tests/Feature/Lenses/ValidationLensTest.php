<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

use Closure;
use ExeQue\Monocle\Lenses\ValidationLens;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Tests\Fixtures\RecordingValidator;
use Tests\Fixtures\Requests\StoreOrderRequest;
use Tests\Fixtures\Rules\Uppercase;

covers(ValidationLens::class);

describe(ValidationLens::class, function (): void {
    it('records the rules, every failed rule with its parameters and the masked input', function (): void {
        Route::post('/signup', fn (Request $request) => $request->validate([
            'email' => 'required|email',
            'name' => 'max:3',
            'plan' => [Rule::in(['free', 'pro'])],
            'password' => 'nullable',
        ]));

        $this->postJson('/signup', ['name' => 'Rumpelstiltskin', 'plan' => 'gold', 'password' => 'hunter22hunter22', 'unvalidated' => 'x'])->assertStatus(422);

        $capture = latestCapture();
        $row = $capture->rows('validation')[0];
        $failed = collect($row['failed'])->keyBy('field');

        expect($row['source'])->toBeNull()
            ->and($row['passed'])->toBeFalse()
            ->and($row['rules'])->toBe(['email' => 'required|email', 'name' => 'max:3', 'plan' => 'in:"free","pro"', 'password' => 'nullable'])
            ->and($failed['email'])->toMatchArray(['rule' => 'Required', 'parameters' => []])
            ->and($failed['name'])->toMatchArray(['rule' => 'Max', 'parameters' => ['3']])
            ->and($failed['name']['message'])->toContain('3')
            ->and($row['fields'])->toBe(4)
            ->and($row['input'])->toBe(['name' => 'Rumpelstiltskin', 'plan' => 'gold', 'password' => 'hunt****'])
            ->and(app(ValidationLens::class)->summarize($capture)['totals'])->toBe(['validations' => 1, 'validation_failed' => 1]);
    });

    it('records custom rule classes, closures and rules given as arrays', function (): void {
        Route::post('/code', fn (Request $request) => $request->validate([
            'code' => ['required', 'string', new Uppercase(3)],
            'note' => ['nullable', function (string $attribute, mixed $value, Closure $fail): void {
                $fail('No notes.');
            }],
        ]));

        $this->postJson('/code', ['code' => 'abc', 'note' => 'hi'])->assertStatus(422);

        $row = latestCapture()->rows('validation')[0];
        $failed = collect($row['failed'])->keyBy('field');

        expect($row['rules']['code'])->toBe('required|string|'.Uppercase::class.' {"minimum":3}')
            ->and($row['rules']['note'])->toStartWith('nullable|Closure (')->toContain('ValidationLensTest.php:')
            ->and($failed['code']['rule'])->toBe(Uppercase::class)
            ->and($failed['code']['message'])->toBe('The code must be uppercase.')
            ->and($failed['note']['rule'])->toBe('Closure')
            ->and($failed['note']['message'])->toBe('No notes.');
    });

    it('describes uploaded files and cuts long input', function (): void {
        Route::post('/upload', fn (Request $request) => count($request->validate(['avatar' => 'file', 'bio' => 'string'])));

        $this->post('/upload', ['avatar' => UploadedFile::fake()->create('me.png', 3, 'image/png'), 'bio' => str_repeat('a', 1500)], ['Accept' => 'application/json'])->assertOk();

        $input = latestCapture()->rows('validation')[0]['input'];

        expect($input['avatar'])->toBe(['file' => 'me.png', 'size' => 3072, 'mime' => 'image/png'])
            ->and(mb_strlen($input['bio']))->toBe(1003);
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
