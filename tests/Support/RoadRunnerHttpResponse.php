<?php

declare(strict_types=1);

namespace Marko\Roadrunner\Tests\Support;

/**
 * One real HTTP response received from a real `rr serve` process, carrying
 * the raw `Set-Cookie` headers so a test can reuse the server-issued cookies
 * (session and XSRF-TOKEN) on a follow-up request and inspect which of them
 * a given request actually set.
 */
readonly class RoadRunnerHttpResponse
{
    /**
     * @param list<string> $setCookies Every `Set-Cookie` value in wire order
     */
    public function __construct(
        public int $statusCode,
        public string $body,
        public array $setCookies,
    ) {}

    /**
     * The last `Set-Cookie` value, or null when the response set none.
     */
    public function lastSetCookie(): ?string
    {
        return $this->setCookies === [] ? null : $this->setCookies[array_key_last($this->setCookies)];
    }

    /**
     * The `Set-Cookie` value for the session cookie, or null when this
     * response did not (re)issue it. The XSRF-TOKEN cookie is issued on
     * every saved session, so "did the server start a session" must look at
     * the session cookie specifically rather than at any Set-Cookie.
     */
    public function sessionCookie(
        string $name = 'marko_session',
    ): ?string {
        return array_find(
            $this->setCookies,
            static fn (string $cookie): bool => str_starts_with($cookie, $name . '='),
        );
    }

    /**
     * Every `name=value` pair from `Set-Cookie`, stripped of attributes
     * (`Path`, `HttpOnly`, ...) and joined the way a browser sends them, so
     * the result is directly usable as the value of a `Cookie` request header.
     */
    public function cookiePair(): ?string
    {
        if ($this->setCookies === []) {
            return null;
        }

        return implode('; ', array_map(
            static fn (string $cookie): string => explode(';', $cookie, 2)[0],
            $this->setCookies,
        ));
    }
}
