<?php

namespace App\Services\DataConnectors;

use App\Models\Connectors\ConnectorAccount;
use Illuminate\Support\Str;

class ConnectorDatasetFingerprint
{
    /**
     * @param array<string, mixed> $dataset
     */
    public function keyFor(ConnectorAccount $account, array $dataset): string
    {
        $providedKey = trim((string) ($dataset['dataset_key'] ?? $dataset['key'] ?? ''));

        if ($providedKey !== '') {
            return $this->normalizeKey($providedKey);
        }

        $type = trim((string) ($dataset['dataset_type'] ?? $dataset['type'] ?? 'dataset'));
        $externalId = trim((string) ($dataset['external_dataset_id'] ?? $dataset['external_id'] ?? $dataset['id'] ?? ''));

        if ($externalId !== '') {
            return $this->normalizeKey($type).':'.sha1($account->provider_key.'|'.$externalId);
        }

        $name = trim((string) ($dataset['display_name'] ?? $dataset['name'] ?? 'unnamed'));

        return $this->normalizeKey($type).':'.sha1($account->provider_key.'|'.$name);
    }

    /**
     * @param array<string, mixed> $dataset
     */
    public function hashFor(ConnectorAccount $account, array $dataset): string
    {
        return hash('sha256', implode('|', [
            $account->id,
            $account->provider_key,
            $this->keyFor($account, $dataset),
        ]));
    }

    private function normalizeKey(string $key): string
    {
        return Str::of($key)
            ->trim()
            ->lower()
            ->replaceMatches('/[^a-z0-9_.:-]+/', '_')
            ->trim('_')
            ->limit(160, '')
            ->toString();
    }
}
