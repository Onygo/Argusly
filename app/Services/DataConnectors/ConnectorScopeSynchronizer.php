<?php

namespace App\Services\DataConnectors;

use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorScope;

class ConnectorScopeSynchronizer
{
    /**
     * @param list<string> $requiredScopes
     * @param list<string> $grantedScopes
     */
    public function sync(ConnectorAccount $account, array $requiredScopes, array $grantedScopes): void
    {
        foreach ($this->normalize($requiredScopes) as $scope) {
            $account->scopes()->updateOrCreate(
                ['scope' => $scope, 'scope_type' => ConnectorScope::TYPE_REQUIRED],
                [
                    'consent_status' => in_array($scope, $grantedScopes, true) ? 'granted' : 'pending',
                    'granted_at' => in_array($scope, $grantedScopes, true) ? now() : null,
                ],
            );
        }

        foreach ($this->normalize($grantedScopes) as $scope) {
            $account->scopes()->updateOrCreate(
                ['scope' => $scope, 'scope_type' => ConnectorScope::TYPE_GRANTED],
                [
                    'consent_status' => 'granted',
                    'granted_at' => now(),
                ],
            );
        }
    }

    /**
     * @param array<int, mixed> $scopes
     * @return list<string>
     */
    private function normalize(array $scopes): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn (mixed $scope): string => trim((string) $scope), $scopes),
            fn (string $scope): bool => $scope !== '',
        )));
    }
}
