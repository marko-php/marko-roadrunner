<?php

declare(strict_types=1);

use Marko\Core\Container\ContainerInterface;
use Marko\Encryption\Contracts\EncryptorInterface;
use Marko\Security\Config\SecurityConfig;
use Marko\Security\Contracts\CsrfTokenManagerInterface;
use Marko\Security\CsrfTokenManager;
use Marko\Security\Middleware\CsrfMiddleware;
use Marko\Session\Contracts\SessionInterface;

// Mirrors packages/security/module.php — CsrfTokenManagerInterface and the
// global CsrfMiddleware are what the CSRF end-to-end flow needs.
return [
    'sequence' => [
        'after' => ['marko/session-file', 'marko/session-database'],
    ],
    'bindings' => [
        CsrfTokenManagerInterface::class => function (ContainerInterface $container): CsrfTokenManagerInterface {
            return new CsrfTokenManager(
                session: $container->get(SessionInterface::class),
                encryptor: $container->get(EncryptorInterface::class),
                sessionKey: $container->get(SecurityConfig::class)->csrfSessionKey(),
            );
        },
    ],
    'globalMiddleware' => [
        CsrfMiddleware::class,
    ],
];
