<?php

namespace App\Services\DataConnectors;

class ConnectorSyncPage
{
    /**
     * @param array<int, array<string, mixed>> $observations
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $rateLimit
     */
    public function __construct(
        public readonly array $observations = [],
        public readonly ?ConnectorSyncCursor $nextCursor = null,
        public readonly bool $hasMore = false,
        public readonly array $metadata = [],
        public readonly array $rateLimit = [],
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $observations
     */
    public static function make(
        array $observations,
        ?ConnectorSyncCursor $nextCursor = null,
        bool $hasMore = false,
    ): self {
        return new self($observations, $nextCursor, $hasMore);
    }
}
