<?php

namespace App\Services\SourceBriefing\Exceptions;

use RuntimeException;
use Throwable;

class SourceBriefingException extends RuntimeException
{
    public function __construct(
        public readonly string $failureCode,
        public readonly string $userMessage,
        string $message = '',
        public readonly bool $retryable = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $userMessage, 0, $previous);
    }
}
