<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use ExeQue\Monocle\Mcp\MonocleServer;
use ExeQue\Monocle\Mcp\Prompts\CompareCaptures;
use ExeQue\Monocle\Mcp\Prompts\InvestigateCapture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server\Transport\FakeTransporter;

covers(InvestigateCapture::class, CompareCaptures::class, MonocleServer::class);

describe('MCP prompts', function (): void {
    it('lists the prompts with their arguments', function (): void {
        $server = app(MonocleServer::class, ['transport' => new FakeTransporter()]);
        $server->start();

        $prompts = $server->createContext()->prompts()->map(fn ($prompt): array => $prompt->toArray())->keyBy('name');

        expect($prompts->keys()->all())->toBe(['investigate', 'compare'])
            ->and($prompts['investigate']['arguments'][0])->toMatchArray(['name' => 'id', 'required' => false])
            ->and($prompts['compare']['arguments'][0])->toMatchArray(['name' => 'before', 'required' => true]);
    });

    it('asks to investigate the latest capture with what Monocle flagged', function (): void {
        Route::get('/things', function () {
            DB::select('select 1');

            abort(500);
        });

        $this->get('/things')->assertServerError();

        MonocleServer::prompt(InvestigateCapture::class)
            ->assertOk()
            ->assertSee('look into capture')
            ->assertSee('GET /things (request, status 500')
            ->assertSee('- error in request: Response status 500.')
            ->assertSee('monocle-compare a=');
    });

    it('leaves out the flagged list when nothing was flagged', function (): void {
        Route::get('/fine', fn () => 'ok');

        $this->get('/fine')->assertOk();

        MonocleServer::prompt(InvestigateCapture::class, ['id' => 'latest'])
            ->assertOk()
            ->assertSee('GET /fine')
            ->assertDontSee('Monocle flagged');
    });

    it('reports a capture that does not exist', function (): void {
        MonocleServer::prompt(InvestigateCapture::class, ['id' => 'nope'])->assertHasErrors();
    });

    it('asks to compare before with after, the latest by default', function (): void {
        MonocleServer::prompt(CompareCaptures::class, ['before' => 'baseline'])
            ->assertOk()
            ->assertSee('monocle-compare a=baseline b=latest');

        MonocleServer::prompt(CompareCaptures::class, ['before' => 'a1', 'after' => 'b2'])
            ->assertSee('monocle-compare a=a1 b=b2');
    });
});
