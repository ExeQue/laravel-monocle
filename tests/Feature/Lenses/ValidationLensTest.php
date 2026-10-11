<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

use Closure;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\ValidationLens;
use Illuminate\Contracts\Validation\Factory as FactoryContract;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Mockery;
use Tests\Fixtures\RecordingValidator;
use Tests\Fixtures\Requests\StoreOrderRequest;
use Tests\Fixtures\Rules\LegacyNotBlank;
use Tests\Fixtures\Rules\LegacyStartsWith;
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
            ->and(app(ValidationLens::class)->summarize($capture)->totals)->toBe(['validations' => 1, 'validation_failed' => 1]);
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

    it('records legacy rule objects and rules given as arrays', function (): void {
        app(Eyepiece::class)->start(CaptureType::Command);

        Validator::make(
            ['sku' => 'xy-1', 'title' => 'Draft', 'name' => 'Rumpelstiltskin'],
            ['sku' => [new LegacyStartsWith('ab-')], 'title' => [new LegacyNotBlank()], 'name' => [['max', 3]]],
        )->passes();

        $row = app(Eyepiece::class)->finalize()->rows('validation')[0];

        expect($row['rules'])->toBe(['sku' => 'starts_with:ab-', 'title' => LegacyNotBlank::class, 'name' => '["max",3]'])
            ->and(array_column($row['failed'], 'rule', 'field'))->toBe(['sku' => LegacyStartsWith::class, 'name' => 'Max']);
    });

    it('records nothing for validators made outside a capture', function (): void {
        Validator::make(['code' => ''], ['code' => 'required'])->passes();

        app(Eyepiece::class)->start(CaptureType::Command);

        expect(app(Eyepiece::class)->finalize()->rows('validation'))->toBe([]);
    });

    it('records a validator once, however often it runs', function (): void {
        app(Eyepiece::class)->start(CaptureType::Command);

        $validator = Validator::make(['code' => ''], ['code' => 'required']);
        $validator->passes();
        $validator->passes();

        expect(app(Eyepiece::class)->finalize()->rows('validation'))->toHaveCount(1);
    });

    it('records nothing when the capture was discarded before validating', function (): void {
        app(Eyepiece::class)->start(CaptureType::Command);
        $validator = Validator::make(['code' => ''], ['code' => 'required']);
        app(Eyepiece::class)->discard();

        expect($validator->passes())->toBeFalse()
            ->and(app(Eyepiece::class)->finalize())->toBeNull();
    });

    it('leaves a validator factory alone that is not the framework one', function (): void {
        $factory = Mockery::mock(FactoryContract::class);
        app()->instance('validator', $factory);

        event(new RouteMatched(new RoutingRoute(['GET'], '/', fn () => 'ok'), Request::create('/')));

        expect(app('validator'))->toBe($factory);
    });

    it('summarizes nothing without validation', function (): void {
        app(Eyepiece::class)->start(CaptureType::Command);

        expect(app(ValidationLens::class)->summarize(app(Eyepiece::class)->finalize()))->toEqual(Summary::make());
    });

    it('describes itself and presents validation runs with their rules', function (): void {
        $lens = app(ValidationLens::class);
        $presentation = $lens->presentation();

        expect($lens->description())->toContain('Validation runs')
            ->and($lens->advice())->toContain('422')
            ->and($presentation->tabView)->toBe('monocle::tabs.validation')
            ->and($presentation->detailsView)->toBe('monocle::details.validation')
            ->and($presentation->detailsOnly)->toBe(['input'])
            ->and($presentation->formats)->toBe(['rules' => ['rules'], 'passed' => ['flag', 'passed', 'failed', 'warn']]);
    });
});
