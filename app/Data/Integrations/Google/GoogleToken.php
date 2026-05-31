<?php

namespace App\Data\Integrations\Google;

use DateTimeInterface;

readonly class GoogleToken
{
    /**
     * @param  array<int, string>  $scopes
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public ?string $accessToken = null,
        public ?string $refreshToken = null,
        public ?DateTimeInterface $expiresAt = null,
        public array $scopes = [],
        public array $payload = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $fallbackScopes
     */
    public static function fromOAuthPayload(array $payload, array $fallbackScopes = []): self
    {
        $scope = $payload['scope'] ?? null;
        $scopes = is_string($scope) ? preg_split('/\s+/', trim($scope)) : $fallbackScopes;

        return new self(
            accessToken: $payload['access_token'] ?? null,
            refreshToken: $payload['refresh_token'] ?? null,
            expiresAt: isset($payload['expires_in']) ? now()->addSeconds((int) $payload['expires_in']) : null,
            scopes: array_values(array_filter($scopes ?: [])),
            payload: $payload,
        );
    }
}
