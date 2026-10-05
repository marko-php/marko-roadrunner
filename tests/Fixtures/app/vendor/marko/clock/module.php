<?php

declare(strict_types=1);

use Marko\Clock\SystemClock;
use Psr\Clock\ClockInterface;

// Mirrors packages/clock/module.php — session-file's SessionMiddleware and
// FileSessionHandler take an injected ClockInterface, so the fixture app
// needs the binding to boot.
return [
    'bindings' => [
        ClockInterface::class => SystemClock::class,
    ],
    'singletons' => [
        ClockInterface::class,
    ],
];
