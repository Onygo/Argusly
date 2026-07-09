<?php

namespace App\Services\DataConnectors;

use App\Models\Connectors\ConnectorOAuthState;
use Illuminate\Support\Carbon;

final class IssuedConnectorOAuthState
{
    public function __construct(
        public readonly ConnectorOAuthState $record,
        public readonly string $state,
        public readonly string $nonce,
        public readonly string $codeVerifier,
        public readonly string $codeChallenge,
    ) {}

    public function expiresAt(): Carbon
    {
        return $this->record->expires_at;
    }
}
