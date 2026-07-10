<?php

namespace App\Services\DataConnectors\Normalization;

use App\Models\Connectors\ConnectorRawRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

abstract class AbstractNormalizedRecordMapper
{
    protected function payload(ConnectorRawRecord $rawRecord): array
    {
        return (array) ($rawRecord->payload_json ?? []);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $paths
     */
    protected function value(array $payload, array $paths, mixed $default = null): mixed
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);

            if ($this->present($value)) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $paths
     */
    protected function string(array $payload, array $paths, ?string $default = null): ?string
    {
        $value = $this->value($payload, $paths);

        if (! $this->present($value)) {
            return $default;
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                $resolved = null;

                foreach ($value as $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    $candidate = $item['id'] ?? $item['value'] ?? $item['name'] ?? null;

                    if ($this->present($candidate)) {
                        $resolved = $candidate;
                        break;
                    }
                }

                $value = $resolved;
            } else {
                $value = $value['id'] ?? $value['value'] ?? $value['name'] ?? null;
            }
        }

        if (! $this->present($value)) {
            return $default;
        }

        $value = trim((string) $value);

        return $value === '' ? $default : $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $paths
     */
    protected function decimal(array $payload, array $paths, ?float $default = null): ?float
    {
        $value = $this->value($payload, $paths);

        if (! $this->present($value)) {
            return $default;
        }

        if (is_array($value)) {
            $value = $value['value'] ?? null;
        }

        if (! is_numeric($value)) {
            return $default;
        }

        return (float) $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $paths
     */
    protected function integer(array $payload, array $paths, int $default = 0): int
    {
        $value = $this->decimal($payload, $paths);

        return $value === null ? $default : (int) $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $paths
     */
    protected function date(array $payload, array $paths, ?string $default = null): ?string
    {
        $value = $this->string($payload, $paths, $default);

        if (! $this->present($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $paths
     */
    protected function dateTime(array $payload, array $paths, ?string $default = null): ?string
    {
        $value = $this->string($payload, $paths, $default);

        if (! $this->present($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function present(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rawReference(ConnectorRawRecord $rawRecord): array
    {
        return Arr::whereNotNull([
            'connector_raw_record_id' => (string) $rawRecord->id,
            'connector_sync_run_id' => $rawRecord->connector_sync_run_id ? (string) $rawRecord->connector_sync_run_id : null,
            'connector_dataset_id' => $rawRecord->connector_dataset_id ? (string) $rawRecord->connector_dataset_id : null,
            'dataset_key' => $rawRecord->dataset_key,
            'record_type' => $rawRecord->record_type,
            'external_record_id' => $rawRecord->external_record_id,
            'fingerprint' => $rawRecord->fingerprint,
        ]);
    }

    protected function emailHash(string $workspaceId, ?string $email): ?string
    {
        $email = strtolower(trim((string) $email));

        if ($email === '') {
            return null;
        }

        $keyMaterial = (string) config('app.key', 'argusly-normalization');
        $workspaceKey = hash('sha256', $workspaceId.'|'.$keyMaterial);

        return hash_hmac('sha256', $email, $workspaceKey);
    }
}
