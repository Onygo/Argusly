<?php

namespace App\Services\WordPress\Exceptions;

use RuntimeException;
use Throwable;

class WordPressConnectorException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?int $statusCode = null,
        private readonly ?string $endpoint = null,
        private readonly string|array|null $responseBody = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    public function endpoint(): ?string
    {
        return $this->endpoint;
    }

    public function responseBody(): string|array|null
    {
        return $this->responseBody;
    }
}
