<?php

namespace App\Support;

final readonly class PublicSiteContext
{
    public function __construct(
        public string $host,
        public string $scheme,
        public string $rootUrl,
        public string $scopeKey,
        public string $type = 'marketing',
        public ?string $workspaceId = null,
        public ?string $clientSiteId = null,
        public array $meta = [],
    ) {}

    public static function fallback(string $host = 'default', string $scheme = 'https'): self
    {
        $normalizedHost = strtolower(trim($host)) ?: 'default';
        $normalizedScheme = strtolower(trim($scheme)) ?: 'https';

        return new self(
            host: $normalizedHost,
            scheme: $normalizedScheme,
            rootUrl: $normalizedHost === 'default'
                ? $normalizedScheme . '://localhost'
                : $normalizedScheme . '://' . $normalizedHost,
            scopeKey: $normalizedHost,
        );
    }
}
