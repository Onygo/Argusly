<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientCreditsException extends RuntimeException
{
    public function __construct(
        public readonly int $requiredCredits,
        public readonly int $availableCredits,
    ) {
        parent::__construct("Insufficient credits. {$requiredCredits} required, {$availableCredits} available.");
    }
}
