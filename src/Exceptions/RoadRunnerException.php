<?php

declare(strict_types=1);

namespace Marko\Roadrunner\Exceptions;

use Marko\Core\Exceptions\MarkoException;

class RoadRunnerException extends MarkoException
{
    public static function binaryNotFound(): self
    {
        return new self(
            message: 'RoadRunner binary not found.',
            context: "While starting the 'rr:serve' command",
            suggestion: "Install the RoadRunner binary before running 'rr:serve':\n\n" .
                "  composer require spiral/roadrunner-cli --dev\n" .
                "  ./vendor/bin/rr get-binary\n\n" .
                'Or download a prebuilt binary from https://roadrunner.dev/download and place it ' .
                'at ./rr or ./vendor/bin/rr (or anywhere on your PATH).',
        );
    }

    public static function temporaryUploadFileUnwritable(
        string $directory,
    ): self {
        return new self(
            message: 'Could not write an uploaded file to a temporary file.',
            context: "While bridging a PSR-7 uploaded file whose stream is not backed by a local file, in '$directory'",
            suggestion: 'Make sure the system temporary directory (sys_get_temp_dir()) exists, is writable by the worker process and has free space.',
        );
    }
}
