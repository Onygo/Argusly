<?php

namespace App\Services\Llm\Exceptions;

use RuntimeException;

class LlmException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?string $provider = null,
        public readonly ?string $requestId = null,
        public readonly ?string $userMessage = null,
    ) {
        parent::__construct($message);
    }
}
