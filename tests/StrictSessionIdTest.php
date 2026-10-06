<?php

declare(strict_types=1);

namespace Marko\Roadrunner\Tests;

use Marko\Core\Application;
use Marko\Roadrunner\Http\Psr7RequestBridge;
use Marko\Roadrunner\Http\Psr7ResponseBridge;
use Marko\Roadrunner\Tests\Support\InProcessRequestHarness;
use Marko\Roadrunner\Tests\Worker\FakePsr7Worker;
use Marko\Roadrunner\Worker\WorkerLogger;
use Marko\Roadrunner\Worker\WorkerRequestHandler;
use Marko\Routing\Http\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * The fixture app's session directory (see Fixtures/app/config/session.php).
 */
function strictSessionFixtureDirectory(): string
{
    return sys_get_temp_dir() . '/marko-roadrunner-harness/' . getmypid() . '/sessions';
}

/**
 * The value a response sets for the session cookie, or null when it sets none.
 */
function strictSessionCookieValue(
    Response $response,
): ?string {
    foreach ($response->cookies() as $cookie) {
        if ($cookie->name() === inProcessHarnessSessionCookieName()) {
            return $cookie->value();
        }
    }

    return null;
}

/**
 * One long-running worker process sees every request below, so these prove
 * strict session ids hold across requests that share PHP's session module
 * state, stat cache and save handler registration.
 */
describe('strict session ids in a long-running worker', function (): void {
    it('discards an unknown session cookie across worker requests without storing it', function (): void {
        $app = Application::boot(inProcessHarnessFixturePath());
        $unknownId = str_repeat('a', 40);
        $psr7Worker = new FakePsr7Worker(array_fill(
            0,
            5,
            inProcessHarnessPsr7Request('GET', '/session/read', $unknownId),
        ));
        $handler = new WorkerRequestHandler(
            psr7Worker: $psr7Worker,
            router: $app->router,
            requestBridge: new Psr7RequestBridge(),
            responseBridge: new Psr7ResponseBridge(),
            logger: new WorkerLogger(),
            container: $app->container,
        );

        $handler->run();

        $setCookies = array_map(
            static fn (ResponseInterface $response): string => $response->getHeaderLine('Set-Cookie'),
            $psr7Worker->responses,
        );

        expect($psr7Worker->responses)->toHaveCount(5)
            ->and(file_exists(strictSessionFixtureDirectory() . '/sess_' . $unknownId))->toBeFalse()
            ->and(array_filter(
                $psr7Worker->responses,
                static fn (ResponseInterface $response): bool => str_contains(
                    (string) $response->getBody(),
                    'session=' . $unknownId,
                ),
            ))->toBeEmpty();

        foreach ($setCookies as $setCookie) {
            expect($setCookie)->toStartWith(inProcessHarnessSessionCookieName() . '=;')
                ->toContain('Expires=');
        }
    })->issue(266);

    it('resumes an issued session cookie across worker requests', function (): void {
        $harness = new InProcessRequestHarness(inProcessHarnessFixturePath());
        $cookieName = inProcessHarnessSessionCookieName();

        $first = $harness->handle(inProcessHarnessRequest('GET', '/session/write'));
        $harness->reset();
        $sessionId = (string) strictSessionCookieValue($first);

        $replayed = $harness->handle(inProcessHarnessRequest('GET', '/session/read', [$cookieName => $sessionId]));
        $harness->reset();

        $replayedAgain = $harness->handle(
            inProcessHarnessRequest('GET', '/session/read', [$cookieName => $sessionId]),
        );
        $harness->reset();

        expect($sessionId)->not->toBe('')
            ->and($replayed->body())->toBe("session=$sessionId;visits=1;user=1")
            ->and(strictSessionCookieValue($replayed))->toBeNull()
            ->and($replayedAgain->body())->toBe("session=$sessionId;visits=1;user=1")
            ->and(strictSessionCookieValue($replayedAgain))->toBeNull();
    })->issue(266);

    it('does not let a cookie for a session from an earlier request resume once it is destroyed', function (): void {
        $harness = new InProcessRequestHarness(inProcessHarnessFixturePath());
        $cookieName = inProcessHarnessSessionCookieName();

        $first = $harness->handle(inProcessHarnessRequest('GET', '/session/write'));
        $harness->reset();
        $sessionId = (string) strictSessionCookieValue($first);
        unlink(strictSessionFixtureDirectory() . '/sess_' . $sessionId);

        $replayed = $harness->handle(inProcessHarnessRequest('GET', '/session/read', [$cookieName => $sessionId]));
        $harness->reset();

        expect($replayed->body())->toContain('visits=0')
            ->and($replayed->body())->not->toContain("session=$sessionId")
            ->and(strictSessionCookieValue($replayed))->toBe('')
            ->and(file_exists(strictSessionFixtureDirectory() . '/sess_' . $sessionId))->toBeFalse();
    })->issue(266);
});
