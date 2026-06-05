<?php

namespace App\Services\Social\LinkedIn;

class LinkedInPublishException extends \RuntimeException
{
    /**
     * @param array<string,mixed> $requestPayload
     * @param array<string,mixed> $responsePayload
     */
    public function __construct(
        string $message,
        public readonly int $responseStatus,
        public readonly array $requestPayload,
        public readonly array $responsePayload,
    ) {
        parent::__construct($message);
    }
}
