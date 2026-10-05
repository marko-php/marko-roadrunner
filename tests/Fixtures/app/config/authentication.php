<?php

declare(strict_types=1);

return [
    'default' => [
        'guard' => 'session',
        'provider' => 'users',
    ],
    'guards' => [
        'session' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],
    'providers' => [
        'users' => [
            'driver' => 'array',
        ],
    ],
    'password' => [
        'driver' => 'bcrypt',
        'bcrypt' => [
            'cost' => 4,
        ],
    ],
    'remember' => [
        'lifetime' => 43200,
        'cookie' => [
            'prefix' => 'remember_',
            'path' => '/',
            'domain' => '',
            'secure' => null,
            'http_only' => true,
            'same_site' => 'Lax',
        ],
    ],
];
