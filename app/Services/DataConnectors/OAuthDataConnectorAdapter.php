<?php

namespace App\Services\DataConnectors;

use App\Models\Connectors\ConnectorAccount;

interface OAuthDataConnectorAdapter extends DataConnectorAdapter
{
    /**
     * @param array<string, mixed> $state
     */
    public function authorizationUrl(array $state = []): string;

    /**
     * @param array<string, mixed> $payload
     */
    public function exchangeAuthorizationCode(string $code, array $payload = []): ConnectorAccount;

    public function refreshAccessToken(ConnectorAccount $account): void;
}
