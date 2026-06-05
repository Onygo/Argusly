<?php

namespace App\Services\SocialDistribution;

class SocialPublishResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $remoteId = null,
        public readonly ?string $remoteUrl = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly ?\DateTimeInterface $rateLimitedUntil = null,
        public readonly array $response = [],
    ) {}

    public static function success(string $remoteId, ?string $remoteUrl = null, array $response = []): self
    {
        return new self(true, remoteId: $remoteId, remoteUrl: $remoteUrl, response: $response);
    }

    public static function failure(string $code, string $message, array $response = []): self
    {
        return new self(false, errorCode: $code, errorMessage: $message, response: $response);
    }

    public static function rateLimited(\DateTimeInterface $until, string $message = 'Platform rate limit reached.'): self
    {
        return new self(false, errorCode: 'RATE_LIMITED', errorMessage: $message, rateLimitedUntil: $until);
    }
}
