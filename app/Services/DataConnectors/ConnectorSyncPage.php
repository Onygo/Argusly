<?php

namespace App\Services\DataConnectors;

class ConnectorSyncPage
{
    /**
     * @param array<int, array<string, mixed>> $observations
     * @param array<int, array<string, mixed>> $rawRecords
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $rateLimit
     */
    public function __construct(
        public readonly array $observations = [],
        public readonly ?ConnectorSyncCursor $nextCursor = null,
        public readonly bool $hasMore = false,
        public readonly array $metadata = [],
        public readonly array $rateLimit = [],
        public readonly array $rawRecords = [],
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $observations
     */
    public static function make(
        array $observations,
        ?ConnectorSyncCursor $nextCursor = null,
        bool $hasMore = false,
        array $rawRecords = [],
    ): self {
        return new self($observations, $nextCursor, $hasMore, rawRecords: $rawRecords);
    }
}
