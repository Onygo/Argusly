<?php

namespace App\Services\DataConnectors;

use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorHealthEvent;
use App\Models\Connectors\ConnectorToken;
use App\Models\User;
use App\Models\Workspace;

interface ConnectorProviderDriver
{
    public function authorize(Workspace $workspace, User $user, ?ConnectorAccount $account = null): ConnectorOAuthAuthorization;

    /**
     * @return array{account: ConnectorAccount, datasets: array<int, mixed>}
     */
    public function callback(string $state, string $code, ?User $user = null): array;

    /**
     * @return array<string, mixed>
     */
    public function discoverDatasets(ConnectorAccount $account): array;

    public function sync(ConnectorAccount $account, string $runType = 'manual'): int;

    public function health(ConnectorAccount $account): ConnectorHealthEvent;

    public function refresh(ConnectorAccount $account): ConnectorToken;

    public function disconnect(ConnectorAccount $account, ?User $user = null): void;
}
