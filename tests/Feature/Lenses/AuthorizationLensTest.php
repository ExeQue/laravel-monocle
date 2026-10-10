<?php

declare(strict_types=1);

use ExeQue\Monocle\Enums\EntryType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\AuthorizationLens;
use Illuminate\Auth\Access\Response;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;

covers(AuthorizationLens::class);

describe(AuthorizationLens::class, function (): void {
    beforeEach(function (): void {
        Gate::define('view-report', fn (GenericUser $user, int $reportId): bool => $reportId === 1);
        Gate::define('publish', fn (GenericUser $user): Response => Response::deny('Not an editor.'));
        Gate::define('anything', fn (?GenericUser $user): bool => true);

        app(Eyepiece::class)->start(EntryType::Request);
    });

    it('records every check with outcome, user and arguments', function (): void {
        $user = new GenericUser(['id' => 7]);

        Gate::forUser($user)->allows('view-report', 1);
        Gate::forUser($user)->allows('view-report', 2);
        Gate::forUser($user)->inspect('publish');
        Gate::allows('anything');

        $entry = app(Eyepiece::class)->finalize();
        $rows = $entry->rows('authorization');

        expect(array_column($rows, 'ability'))->toBe(['view-report', 'view-report', 'publish', 'anything'])
            ->and(array_column($rows, 'allowed'))->toBe([true, false, false, true])
            ->and($rows[0]['user'])->toBe(GenericUser::class . '#7')
            ->and($rows[0]['arguments'])->toBe([1])
            ->and($rows[2]['message'])->toBe('Not an editor.')
            ->and($rows[3]['user'])->toBeNull()
            ->and($rows[0]['origin']['file'])->toContain('AuthorizationLensTest.php');

        $summary = (new AuthorizationLens())->summarize($entry);

        expect($summary['totals'])->toBe(['authorization_checks' => 4, 'authorization_denied' => 2])
            ->and($summary['warnings'])->toBe([
                'Authorization denied: view-report for ' . GenericUser::class . '#7',
                'Authorization denied: publish for ' . GenericUser::class . '#7',
            ]);
    });

    it('summarizes nothing without checks', function (): void {
        expect((new AuthorizationLens())->summarize(app(Eyepiece::class)->finalize()))->toBe([]);
    });
});
