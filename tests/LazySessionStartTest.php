<?php

declare(strict_types=1);

namespace Marko\Roadrunner\Tests;

use Marko\Roadrunner\Tests\Support\InProcessRequestHarness;
use Marko\Routing\Http\Request;
use Marko\Routing\Http\Response;
use Marko\Session\Contracts\SessionInterface;

/**
 * The session cookie a response sets, or null when it sets none.
 */
function lazySessionCookie(
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
 * Cookieless requests only arm the session; it starts on first access. These
 * drive the real fixture app (session-file, SessionGuard, CSRF) through one
 * booted application, resetting between requests exactly as the worker does.
 */
describe('lazy session start in a long-running worker', function (): void {
    it('regenerates and persists a login on a cookieless request', function (): void {
        $harness = new InProcessRequestHarness(inProcessHarnessFixturePath());

        $login = $harness->handle(inProcessHarnessRequest('GET', '/session/write'));
        $harness->reset();
        $sessionId = lazySessionCookie($login);

        $resumed = $harness->handle(inProcessHarnessRequest(
            'GET',
            '/session/read',
            [inProcessHarnessSessionCookieName() => (string) $sessionId],
        ));

        expect($sessionId)->not->toBeNull()
            ->and($sessionId)->not->toBe('')
            ->and($login->body())->toContain('user=1')
            ->and($resumed->body())->toBe("session=$sessionId;visits=1;user=1");
    });

    it('issues and persists a csrf token on a cookieless request', function (): void {
        $harness = new InProcessRequestHarness(inProcessHarnessFixturePath());

        $tokenResponse = $harness->handle(inProcessHarnessRequest('GET', '/csrf/token'));
        $harness->reset();
        $sessionId = (string) lazySessionCookie($tokenResponse);

        $submit = $harness->handle(new Request(
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/csrf/submit'],
            post: ['_token' => $tokenResponse->body()],
            cookies: [inProcessHarnessSessionCookieName() => $sessionId],
        ));

        expect($sessionId)->not->toBe('')
            ->and($submit->body())->toBe('csrf-ok');
    });

    it('serves consecutive cookieless requests in one process without leaking session data', function (): void {
        $harness = new InProcessRequestHarness(inProcessHarnessFixturePath());

        $write = $harness->handle(inProcessHarnessRequest('GET', '/session/write'));
        $harness->reset();
        $read = $harness->handle(inProcessHarnessRequest('GET', '/session/read'));

        $writtenId = (string) lazySessionCookie($write);

        expect($write->body())->toBe("session=$writtenId;visits=1;user=1")
            ->and($read->body())->toEndWith(';visits=0;user=guest')
            ->and($read->body())->not->toContain($writtenId)
            ->and(lazySessionCookie($read))->toBeNull();
    });

    it('leaves the session neither started nor armed after a cookieless request', function (): void {
        $harness = new InProcessRequestHarness(inProcessHarnessFixturePath());

        (void) $harness->handle(inProcessHarnessRequest('GET', '/session/read'));
        $session = $harness->container()->get(SessionInterface::class);

        expect($session->started)->toBeFalse()
            ->and($session->isAvailable())->toBeFalse();
    });
});
