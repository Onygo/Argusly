<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientCreditsException extends RuntimeException
{
    public function __construct(
        public readonly int $required,
        public readonly int $available,
        ?string $message = null
    ) {
        parent::__construct($message ?: sprintf(
            'Insufficient credits. Required: %d, available: %d.',
            $required,
            $available
        ));
    }
}
