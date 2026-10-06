<?php

declare(strict_types=1);

use Marko\Authentication\AuthManager;
use Marko\Authentication\Config\AuthConfig;
use Marko\Authentication\Contracts\CookieJarInterface;
use Marko\Authentication\Contracts\GuardInterface;
use Marko\Authentication\Contracts\LoginThrottleInterface;
use Marko\Authentication\Contracts\PasswordHasherInterface;
use Marko\Authentication\Cookie\RequestCookieJar;
use Marko\Authentication\Hashing\BcryptPasswordHasher;
use Marko\Authentication\Http\CurrentRequest;
use Marko\Authentication\Middleware\QueuedCookiesMiddleware;
use Marko\Authentication\Throttle\LoginThrottle;
use Marko\Authentication\Throttle\NullLoginThrottle;
use Marko\Authentication\Token\RememberTokenManager;
use Marko\Cache\Contracts\CacheInterface;
use Marko\Core\Container\ContainerInterface;
use Marko\RateLimiter\Contracts\RateLimitKeyResolverInterface;
use Psr\Clock\ClockInterface;

// Mirrors packages/authentication/module.php — AuthManager and GuardInterface
// as singletons are precisely what task 005/006 hunt for leaks in.
return [
    // Load after the session drivers so QueuedCookiesMiddleware runs inside SessionMiddleware.
    'sequence' => [
        'after' => ['marko/session-file', 'marko/session-database'],
    ],
    'bindings' => [
        PasswordHasherInterface::class => function (ContainerInterface $container): PasswordHasherInterface {
            $config = $container->get(AuthConfig::class);

            return new BcryptPasswordHasher(
                cost: $config->bcryptCost(),
            );
        },
        RememberTokenManager::class => function (ContainerInterface $container): RememberTokenManager {
            return new RememberTokenManager(
                clock: $container->get(ClockInterface::class),
                lifetimeMinutes: $container->get(AuthConfig::class)->rememberLifetime(),
            );
        },
        // The guard and QueuedCookiesMiddleware must share one jar so queued cookies reach the response.
        CookieJarInterface::class => function (ContainerInterface $container): CookieJarInterface {
            return $container->get(RequestCookieJar::class);
        },
        GuardInterface::class => function (ContainerInterface $container): GuardInterface {
            return $container->get(AuthManager::class)->guard();
        },
        // Counts failed logins in the cache. A missing cache driver only fails when a
        // login is attempted, so guards that never call attempt() keep working. The
        // client identity comes from marko/ratelimiter when it is installed.
        LoginThrottleInterface::class => function (ContainerInterface $container): LoginThrottleInterface {
            $config = $container->get(AuthConfig::class);

            if (!$config->throttleEnabled()) {
                return new NullLoginThrottle();
            }

            return new LoginThrottle(
                cache: $container->has(CacheInterface::class) ? $container->get(CacheInterface::class) : null,
                clock: $container->get(ClockInterface::class),
                currentRequest: $container->get(CurrentRequest::class),
                maxAttempts: $config->throttleMaxAttempts(),
                decaySeconds: $config->throttleDecaySeconds(),
                lockoutSeconds: $config->throttleLockoutSeconds(),
                maxLockoutSeconds: $config->throttleMaxLockoutSeconds(),
                rateLimitKeyResolver: $container->has(RateLimitKeyResolverInterface::class)
                    ? $container->get(RateLimitKeyResolverInterface::class)
                    : null,
            );
        },
    ],
    'singletons' => [
        AuthManager::class,
        GuardInterface::class,
        RequestCookieJar::class,
        CookieJarInterface::class,
        RememberTokenManager::class,
        CurrentRequest::class,
        LoginThrottleInterface::class,
    ],
    'globalMiddleware' => [
        QueuedCookiesMiddleware::class,
    ],
];
