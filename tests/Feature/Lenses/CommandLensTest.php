<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Lenses\CommandLens;
use ExeQue\Monocle\Reader\CaptureReader;
use ExeQue\Monocle\Reader\Filter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Mockery;
use RuntimeException;
use Tests\TestCase;

covers(CommandLens::class);

describe(CommandLens::class, function (): void {
    beforeEach(function (): void {
        Artisan::command('monocle-test:ping {name} {--secret=}', function (): int {
            $this->line('pong');

            return 3;
        });

        Artisan::command('monocle-test:ok', fn (): int => 0);

        Artisan::command('monocle-test:outer', function (): int {
            Artisan::call('monocle-test:ok');

            return 0;
        });

        Route::get('/call-ok', function () {
            Artisan::call('monocle-test:ok');

            return 'called';
        });

        Route::get('/call', function () {
            Artisan::call('monocle-test:ping', ['name' => 'nested']);

            return 'called';
        });
    });

    $count = fn (string $type): int => app(CaptureReader::class)->list(Filter::fromArray(['type' => $type]))['total'];

    it('records a command run as a capture with arguments, masked options and exit code', function (): void {
        Artisan::call('monocle-test:ping', ['name' => 'ada', '--secret' => 'hunter22hunter22']);

        $capture = latestCapture();
        $command = $capture->section('command');

        expect($capture->type)->toBe(CaptureType::Command)
            ->and($capture->name)->toBe('artisan monocle-test:ping')
            ->and($capture->status)->toBe('3')
            ->and($command['arguments']['name'])->toBe('ada')
            ->and($command['options']['secret'])->toBe('hunt****')
            ->and($command['exit_code'])->toBe(3)
            ->and(array_map(fn ($span): string => $span->name, $capture->spansOfKind('phase')))->toContain('command');
    });

    it('reports a command capture that exited with a non-zero code as an error', function (): void {
        Artisan::call('monocle-test:ping', ['name' => 'ada']);

        $row = app(CaptureReader::class)->list(Filter::fromArray(['type' => 'command']))['rows'][0];

        expect(app(CommandLens::class)->summarize(latestCapture())->errors)->toBe(['Command exited with code 3.'])
            ->and($row['has_errors'])->toBeTrue();
    });

    it('reports no error for a command capture that exited with code 0', function (): void {
        Artisan::call('monocle-test:ok');

        $row = app(CaptureReader::class)->list(Filter::fromArray(['type' => 'command']))['rows'][0];

        expect(app(CommandLens::class)->summarize(latestCapture())->errors)->toBe([])
            ->and($row['has_errors'])->toBeFalse();
    });

    it('masks arguments whose name matches the input mask', function (): void {
        Artisan::command('monocle-test:login {token} {user}', fn (): int => 0);

        Artisan::call('monocle-test:login', ['token' => 'hunter22hunter22', 'user' => 'ada']);

        expect(latestCapture()->section('command')['arguments'])->toBe(['token' => 'hunt****', 'user' => 'ada']);
    });

    it('closes its own phase and keeps request data out of command captures', function (): void {
        Artisan::call('monocle-test:ping', ['name' => 'ada']);

        $capture = latestCapture();
        $summary = app(\ExeQue\Monocle\Reader\SummaryBuilder::class)->build($capture);

        expect($capture->section('command')['class'])->toBeNull()
            ->and($capture->sections)->not->toHaveKey('request')
            ->and($capture->health['open_spans_at_finalize'])->toBe(0)
            ->and($summary['warnings'])->toBe([])
            ->and($summary['totals']['memory_peak_mb'])->toBeGreaterThan(0)
            ->and(array_column($summary['breakdown']['phases'], 'name'))->toContain('command');
    });

    it('records the class behind a command', function (): void {
        Artisan::call('env');

        expect(latestCapture()->section('command')['class'])->toBe(\Illuminate\Foundation\Console\EnvironmentCommand::class);
    });

    it('records nested commands as spans inside the active capture', function () use ($count): void {
        $this->get('/call')->assertOk();

        $request = latestCapture();
        $spans = $request->spansOfKind('command');

        expect($request->type)->toBe(CaptureType::Request)
            ->and($spans)->toHaveCount(1)
            ->and($spans[0]->name)->toBe('monocle-test:ping')
            ->and($spans[0]->meta['exit_code'])->toBe(3)
            ->and($count('command'))->toBe(0);
    });

    it('records nested commands as rows with masked options, exit code and duration', function (): void {
        Route::get('/call-secret', function () {
            Artisan::call('monocle-test:ping', ['name' => 'nested', '--secret' => 'hunter22hunter22']);

            return 'called';
        });

        $this->get('/call-secret')->assertOk();

        $request = latestCapture();
        $row = $request->rows('commands')[0];

        expect($request->rows('commands'))->toHaveCount(1)
            ->and($row['name'])->toBe('monocle-test:ping')
            ->and($row['class'])->toBeNull()
            ->and($row['arguments'])->toBe(['name' => 'nested'])
            ->and($row['options']['secret'])->toBe('hunt****')
            ->and($row['exit_code'])->toBe(3)
            ->and($row['failed'])->toBeTrue()
            ->and($row['duration'])->toBeFloat()
            ->and($row['time'])->toBeFloat()
            ->and($row['origin']['file'])->toContain('CommandLensTest.php');
    });

    it('reports a failed nested command as an error with totals', function (): void {
        $this->get('/call')->assertOk();

        $summary = app(\ExeQue\Monocle\Reader\SummaryBuilder::class)->build(latestCapture());

        expect($summary['errors'])->toContain('Command monocle-test:ping failed (exit code 3).')
            ->and($summary['totals']['commands'])->toBe(1)
            ->and($summary['totals']['commands_failed'])->toBe(1)
            ->and($summary['totals'])->toHaveKey('commands_ms')
            ->and($summary['problems']['command']['errors'])->toBe(1);
    });

    it('reports no error for a successful nested command and nothing without one', function (): void {
        $this->get('/call-ok')->assertOk();

        $lens = app(CommandLens::class);
        $ok = $lens->summarize(latestCapture());

        expect($ok->errors)->toBe([])
            ->and($ok->totals['commands_failed'])->toBe(0)
            ->and(latestCapture()->rows('commands')[0]['failed'])->toBeFalse()
            ->and($lens->summarize(new \ExeQue\Monocle\Data\Capture('c1', CaptureType::Request, microtime(true)))->totals)->toBe([]);
    });

    it('shows nested commands on a Commands tab, or on the command tab of a command capture', function (): void {
        $this->get('/call')->assertOk();

        $tabs = app(\ExeQue\Monocle\Dashboard\TabList::class)->for(latestCapture(), [], ['command' => ['warnings' => 0, 'errors' => 1]]);
        $tab = array_values(array_filter($tabs, fn (array $tab): bool => $tab['key'] === 'commands'))[0];

        Artisan::call('monocle-test:outer');

        $command = latestCapture();

        expect($tab)->toMatchArray(['label' => 'Commands', 'count' => 1, 'errors' => 1])
            ->and($command->section('command')['name'])->toBe('monocle-test:outer')
            ->and($command->section('command')['exit_code'])->toBe(0)
            ->and($command->rows('commands')[0]['name'])->toBe('monocle-test:ok')
            ->and(app(CommandLens::class)->presentation()->includes)->toBe(['commands']);
    });

    it('never records the Monocle MCP server process', function () use ($count): void {
        $input = new \Symfony\Component\Console\Input\ArrayInput(
            ['handle' => 'monocle'],
            new \Symfony\Component\Console\Input\InputDefinition([new \Symfony\Component\Console\Input\InputArgument('handle')]),
        );
        $output = new \Symfony\Component\Console\Output\NullOutput();

        event(new \Illuminate\Console\Events\CommandStarting('mcp:start', $input, $output));
        event(new \Illuminate\Console\Events\CommandFinished('mcp:start', $input, $output, 0));

        expect($count('command'))->toBe(0)
            ->and(app(\ExeQue\Monocle\Eyepiece::class)->active())->toBeNull();
    });

    it('ignores excepted commands', function () use ($count): void {
        Artisan::call('monocle:list');

        expect($count('command'))->toBe(0);
    });

    it('ignores the commands an excepted command calls, and records again once it has finished', function () use ($count): void {
        config()->push('monocle.except.commands', 'monocle-test:outer');

        Artisan::call('monocle-test:outer');

        expect($count('command'))->toBe(0)
            ->and(app(\ExeQue\Monocle\Eyepiece::class)->active())->toBeNull();

        Artisan::call('monocle-test:ok');

        expect($count('command'))->toBe(1);
    });

    it('leaves Laravel\'s built-in housekeeping commands out by default', function () use ($count): void {
        $input = new \Symfony\Component\Console\Input\ArrayInput([]);
        $output = new \Symfony\Component\Console\Output\NullOutput();

        foreach (['migrate', 'migrate:fresh', 'db:seed', 'make:model', 'vendor:publish', 'config:clear', 'route:list', 'optimize'] as $command) {
            event(new \Illuminate\Console\Events\CommandStarting($command, $input, $output));
            event(new \Illuminate\Console\Events\CommandFinished($command, $input, $output, 0));
        }

        expect($count('command'))->toBe(0)
            ->and(app(\ExeQue\Monocle\Eyepiece::class)->active())->toBeNull();
    });

    it('still records schedule:run', function () use ($count): void {
        $input = new \Symfony\Component\Console\Input\ArrayInput([]);
        $output = new \Symfony\Component\Console\Output\NullOutput();

        event(new \Illuminate\Console\Events\CommandStarting('schedule:run', $input, $output));
        event(new \Illuminate\Console\Events\CommandFinished('schedule:run', $input, $output, 0));

        expect($count('command'))->toBe(1);
    });

    it('records nothing for a nested command while the active capture is discarded', function (): void {
        $eyepiece = app(\ExeQue\Monocle\Eyepiece::class);
        $eyepiece->start(CaptureType::Request);
        $eyepiece->discard();

        Artisan::call('monocle-test:ok');

        expect($eyepiece->active()->type)->toBe(CaptureType::Request)
            ->and($eyepiece->active()->rows('commands'))->toBe([])
            ->and($eyepiece->active()->spansOfKind('command'))->toBe([]);
    });

    it('ignores a finished command without a capture of its own', function () use ($count): void {
        $input = new \Symfony\Component\Console\Input\ArrayInput([]);
        $output = new \Symfony\Component\Console\Output\NullOutput();

        event(new \Illuminate\Console\Events\CommandFinished('monocle-test:ok', $input, $output, 0));

        expect(app(\ExeQue\Monocle\Eyepiece::class)->active())->toBeNull()
            ->and($count('command'))->toBe(0);
    });

    it('records no class for a command the kernel does not know', function (): void {
        $input = new \Symfony\Component\Console\Input\ArrayInput([]);
        $output = new \Symfony\Component\Console\Output\NullOutput();

        event(new \Illuminate\Console\Events\CommandStarting('monocle-test:unknown', $input, $output));

        expect(app(\ExeQue\Monocle\Eyepiece::class)->active()->section('command'))->toMatchArray(['name' => 'monocle-test:unknown', 'class' => null]);
    });

    it('records no class when the console kernel cannot list its commands', function (): void {
        $kernel = Mockery::mock(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->shouldReceive('all')->andThrow(new RuntimeException('kernel unavailable'));
        $this->app->instance(\Illuminate\Contracts\Console\Kernel::class, $kernel);

        $input = new \Symfony\Component\Console\Input\ArrayInput([]);
        $output = new \Symfony\Component\Console\Output\NullOutput();

        event(new \Illuminate\Console\Events\CommandStarting('env', $input, $output));

        expect(app(\ExeQue\Monocle\Eyepiece::class)->active()->section('command'))->toMatchArray(['name' => 'env', 'class' => null]);
    });

    it('advises the agent when to look at it', function (): void {
        expect(app(CommandLens::class)->advice())->toContain('exit code');
    });

    describe('trace from the environment', function (): void {
        $parent = '019a0000-0000-7000-8000-000000000001';
        $trace = '019a0000-0000-7000-8000-000000000002';

        afterEach(function (): void {
            putenv('MONOCLE_PARENT_ID');
            putenv('MONOCLE_TRACE_ID');
        });

        it('joins the trace of the capture that started the process', function () use ($parent, $trace): void {
            putenv('MONOCLE_PARENT_ID='.$parent);
            putenv('MONOCLE_TRACE_ID='.$trace);

            Artisan::call('monocle-test:ok');

            $capture = latestCapture();

            expect($capture->parentId)->toBe($parent)
                ->and($capture->traceId)->toBe($trace)
                ->and($capture->linkedVia)->toBe('process');
        });

        it('stays a root when trace.processes is off or the ids are not capture ids', function (string $parentId, bool $enabled) use ($trace): void {
            config(['monocle.trace.processes' => $enabled]);
            putenv('MONOCLE_PARENT_ID='.$parentId);
            putenv('MONOCLE_TRACE_ID='.$trace);

            Artisan::call('monocle-test:ok');

            $capture = latestCapture();

            expect($capture->parentId)->toBeNull()
                ->and($capture->traceId)->toBe($capture->id)
                ->and($capture->linkedVia)->toBeNull();
        })->with([
            'turned off' => [$parent, false],
            'not a capture id' => ['nope', true],
        ]);
    });

    describe('printing the capture of a failed run', function (): void {
        $run = function (string $command, array $parameters = []): string {
            Artisan::call($command, $parameters);
            app()->terminate();

            return Artisan::output();
        };

        it('prints the id of the stored capture when a command exits with a non-zero code', function () use ($run): void {
            $output = $run('monocle-test:ping', ['name' => 'ada']);
            $id = latestCapture()->id;

            expect($output)->toBe(implode(PHP_EOL, [
                'pong',
                'Monocle captured this run as '.$id,
                '  php artisan monocle:show '.$id,
                '  http://localhost/_monocle#'.$id,
                '',
            ]));
        });

        it('prints only once per failed run', function () use ($run): void {
            $run('monocle-test:ping', ['name' => 'ada']);
            $this->app->terminate();

            expect(Artisan::output())->toBe('');
        });

        it('prints to the error output of a console output', function (): void {
            $input = new \Symfony\Component\Console\Input\ArrayInput([]);
            $output = new \Symfony\Component\Console\Output\ConsoleOutput();
            $output->setErrorOutput($errors = new \Symfony\Component\Console\Output\BufferedOutput());

            event(new \Illuminate\Console\Events\CommandStarting('monocle-test:ok', $input, $output));
            event(new \Illuminate\Console\Events\CommandFinished('monocle-test:ok', $input, $output, 2));
            $this->app->terminate();

            expect($errors->fetch())->toStartWith('Monocle captured this run as '.latestCapture()->id);
        });

        it('prints below the exception of a command that threw', function (): void {
            Artisan::command('monocle-test:boom', fn (): int => throw new RuntimeException('the command went boom'));

            $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
            $input = new \Symfony\Component\Console\Input\ArrayInput(['command' => 'monocle-test:boom']);
            $output = new \Symfony\Component\Console\Output\BufferedOutput();

            // Testbench's kernel rethrows what Laravel's catches and renders; do what Laravel's does.
            try {
                $kernel->handle($input, $output);
            } catch (RuntimeException $e) {
                app(\Illuminate\Contracts\Debug\ExceptionHandler::class)->renderForConsole($output, $e);
            }

            $kernel->terminate($input, 1);
            $text = $output->fetch();

            expect($text)->toContain('the command went boom')
                ->and(strpos($text, 'Monocle captured this run as '.latestCapture()->id))->toBeGreaterThan(strpos($text, 'the command went boom'));
        });

        it('prints nothing for a command that succeeded', function () use ($run): void {
            expect($run('monocle-test:ok'))->toBe('');
        });

        it('prints nothing for a failed command called inside another capture', function () use ($run): void {
            Artisan::command('monocle-test:calls-failing', function (): int {
                Artisan::call('monocle-test:ping', ['name' => 'nested']);

                return 0;
            });

            expect($run('monocle-test:calls-failing'))->not->toContain('Monocle captured');
        });

        it('prints nothing when the capture was not stored', function () use ($run): void {
            Artisan::command('monocle-test:discarded', function (): int {
                app(\ExeQue\Monocle\Eyepiece::class)->discard();

                return 3;
            });
            config()->push('monocle.except.commands', 'monocle-test:ping');

            expect($run('monocle-test:discarded'))->toBe('')
                ->and($run('monocle-test:ping', ['name' => 'ada']))->toBe('pong'.PHP_EOL);
        });

        it('prints nothing with the output quiet', function () use ($run): void {
            expect($run('monocle-test:ping', ['name' => 'ada', '--quiet' => true]))->toBe('');
        });

        it('leaves the dashboard link out when the dashboard is off or its url cannot be built', function () use ($run): void {
            config(['monocle.dashboard.enabled' => false]);
            $disabled = $run('monocle-test:ping', ['name' => 'ada']);

            config(['monocle.dashboard.enabled' => true]);
            Artisan::call('monocle-test:ping', ['name' => 'ada']);
            $url = Mockery::mock(\Illuminate\Routing\UrlGenerator::class);
            $url->shouldReceive('route')->andThrow(new RuntimeException('no url'));
            $this->app->instance('url', $url);
            $this->app->terminate();

            expect($disabled)->toContain('php artisan monocle:show')->not->toContain('_monocle#')
                ->and(Artisan::output())->toContain('php artisan monocle:show')->not->toContain('_monocle#');
        });

        it('prints nothing with print_failed_capture turned off', function () use ($run): void {
            TestCase::$bootConfig = ['monocle.lenses.'.CommandLens::class => ['print_failed_capture' => false]];
            $this->refreshApplication();
            TestCase::$bootConfig = [];

            Artisan::command('monocle-test:fail', fn (): int => 3);

            expect($run('monocle-test:fail'))->toBe('')
                ->and(latestCapture()->section('command')['exit_code'])->toBe(3);
        });
    });
});
