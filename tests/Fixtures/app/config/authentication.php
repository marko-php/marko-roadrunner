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
    'throttle' => [
        'enabled' => true,
        'max_attempts' => 5,
        'decay_seconds' => 60,
        'lockout_seconds' => 60,
        'max_lockout_seconds' => 3600,
    ],
];
