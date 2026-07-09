<?php

namespace App\Services\DataConnectors;

use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorDataset;
use App\Models\Connectors\ConnectorHealthEvent;

class ConnectorHealthService
{
    /**
     * @return array<string, mixed>
     */
    public function retentionPolicy(): array
    {
        return [
            'enabled' => (bool) config('data_connectors.health.retention.enabled', false),
            'days' => (int) config('data_connectors.health.retention.days', 180),
            'destructive_cleanup_enabled' => false,
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    public function record(
        ConnectorAccount $account,
        string $severity,
        string $eventType,
        string $message,
        array $context = [],
        ?ConnectorDataset $dataset = null,
    ): ConnectorHealthEvent {
        $event = ConnectorHealthEvent::query()->create([
            'connector_account_id' => $account->id,
            'connector_dataset_id' => $dataset?->id,
            'workspace_id' => $account->workspace_id,
            'client_site_id' => $dataset?->client_site_id ?? $account->client_site_id,
            'provider_key' => $account->provider_key,
            'severity' => $severity,
            'event_type' => $eventType,
            'message' => $message,
            'context_json' => $this->sanitizeContext($context),
            'occurred_at' => now(),
        ]);

        $this->rollUp($account, $event, $dataset);

        return $event;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function resolve(
        ConnectorAccount $account,
        string $message = 'Connector health recovered.',
        array $context = [],
        ?ConnectorDataset $dataset = null,
    ): ConnectorHealthEvent {
        return $this->record(
            account: $account,
            severity: ConnectorHealthEvent::SEVERITY_INFO,
            eventType: ConnectorHealthEvent::EVENT_RESOLVED,
            message: $message,
            context: $context,
            dataset: $dataset,
        );
    }

    public function latestForAccount(ConnectorAccount $account): ?ConnectorHealthEvent
    {
        if ($account->latest_health_event_id) {
            return ConnectorHealthEvent::query()->find($account->latest_health_event_id);
        }

        return $account->healthEvents()
            ->latest('occurred_at')
            ->latest('created_at')
            ->latest('id')
            ->first();
    }

    public function latestForDataset(ConnectorDataset $dataset): ?ConnectorHealthEvent
    {
        if ($dataset->latest_health_event_id) {
            return ConnectorHealthEvent::query()->find($dataset->latest_health_event_id);
        }

        return $dataset->healthEvents()
            ->latest('occurred_at')
            ->latest('created_at')
            ->latest('id')
            ->first();
    }

    public function statusFor(string $severity, string $eventType): string
    {
        if (in_array($eventType, [
            ConnectorHealthEvent::EVENT_RESOLVED,
            ConnectorHealthEvent::EVENT_RECOVERED,
        ], true)) {
            return ConnectorHealthEvent::STATUS_HEALTHY;
        }

        if ($eventType === ConnectorHealthEvent::EVENT_TOKEN_EXPIRED) {
            return ConnectorHealthEvent::STATUS_EXPIRED_TOKEN;
        }

        if ($eventType === ConnectorHealthEvent::EVENT_RECONNECT_REQUIRED) {
            return ConnectorHealthEvent::STATUS_NEEDS_RECONNECT;
        }

        if ($eventType === ConnectorHealthEvent::EVENT_RATE_LIMITED) {
            return ConnectorHealthEvent::STATUS_RATE_LIMITED;
        }

        if ($eventType === ConnectorHealthEvent::EVENT_DISABLED) {
            return ConnectorHealthEvent::STATUS_DISABLED;
        }

        return match ($severity) {
            ConnectorHealthEvent::SEVERITY_WARNING => ConnectorHealthEvent::STATUS_WARNING,
            ConnectorHealthEvent::SEVERITY_ERROR => ConnectorHealthEvent::STATUS_ERROR,
            ConnectorHealthEvent::SEVERITY_CRITICAL => ConnectorHealthEvent::STATUS_CRITICAL,
            default => ConnectorHealthEvent::STATUS_HEALTHY,
        };
    }

    private function rollUp(
        ConnectorAccount $account,
        ConnectorHealthEvent $event,
        ?ConnectorDataset $dataset,
    ): void {
        $state = [
            'health_status' => $this->statusFor($event->severity, $event->event_type),
            'health_severity' => $event->severity,
            'latest_health_event_id' => $event->id,
            'health_checked_at' => $event->occurred_at,
        ];

        $account->forceFill($state)->save();

        if ($dataset instanceof ConnectorDataset) {
            $dataset->forceFill($state)->save();
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function sanitizeContext(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            $keyString = (string) $key;

            if ($this->isSecretKey($keyString)) {
                $sanitized[$keyString] = '[redacted]';

                continue;
            }

            $sanitized[$keyString] = is_array($value)
                ? $this->sanitizeContext($value)
                : $value;
        }

        return $sanitized;
    }

    private function isSecretKey(string $key): bool
    {
        return preg_match('/(secret|token|password|authorization|api[_-]?key|client[_-]?secret)/i', $key) === 1;
    }
}
