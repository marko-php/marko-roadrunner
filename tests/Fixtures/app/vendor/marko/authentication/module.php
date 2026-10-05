<?php

declare(strict_types=1);

use Marko\Authentication\AuthManager;
use Marko\Authentication\Config\AuthConfig;
use Marko\Authentication\Contracts\CookieJarInterface;
use Marko\Authentication\Contracts\GuardInterface;
use Marko\Authentication\Contracts\PasswordHasherInterface;
use Marko\Authentication\Cookie\RequestCookieJar;
use Marko\Authentication\Hashing\BcryptPasswordHasher;
use Marko\Authentication\Middleware\QueuedCookiesMiddleware;
use Marko\Authentication\Token\RememberTokenManager;
use Marko\Core\Container\ContainerInterface;

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
    ],
    'singletons' => [
        AuthManager::class,
        GuardInterface::class,
        RequestCookieJar::class,
        CookieJarInterface::class,
        RememberTokenManager::class,
    ],
    'globalMiddleware' => [
        QueuedCookiesMiddleware::class,
    ],
];
