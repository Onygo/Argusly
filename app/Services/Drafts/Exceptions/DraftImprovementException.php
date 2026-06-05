<?php

namespace App\Services\Drafts\Exceptions;

use RuntimeException;
use Throwable;

class DraftImprovementException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $action,
        public readonly ?string $provider = null,
        public readonly ?string $model = null,
        public readonly ?string $requestId = null,
        public readonly ?string $userMessage = null,
        public readonly ?string $responsePreview = null,
        public readonly ?string $failureStage = null,
        public readonly ?string $internalReason = null,
        public readonly bool $retryable = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
