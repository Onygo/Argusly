<?php

namespace App\Services\DataConnectors;

final class ConnectorOAuthAuthorization
{
    public function __construct(
        public readonly string $url,
        public readonly IssuedConnectorOAuthState $state,
    ) {}
}
