<?php

namespace App\Services\DataConnectors;

use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorDataset;
use App\Models\Connectors\ConnectorHealthEvent;
use App\Models\Connectors\ConnectorQuotaBudget;
use Illuminate\Support\Carbon;

class ConnectorRateLimitService
{
    public function __construct(
        private readonly DataConnectorRegistry $registry,
        private readonly ConnectorHealthService $health,
    ) {}

    public function canAttempt(ConnectorAccount $account, ?ConnectorDataset $dataset = null): bool
    {
        unset($dataset);

        return collect($this->ensureBudgets($account))
            ->every(fn (ConnectorQuotaBudget $budget): bool => $budget->status !== ConnectorQuotaBudget::STATUS_HARD_STOP);
    }

    public function consume(ConnectorAccount $account, int $amount = 1, ?ConnectorDataset $dataset = null): array
    {
        unset($dataset);

        $budgets = collect($this->ensureBudgets($account))
            ->map(function (ConnectorQuotaBudget $budget) use ($amount, $account): ConnectorQuotaBudget {
                $budget->forceFill([
                    'used_value' => max(0, (int) $budget->used_value + max(1, $amount)),
                ])->save();

                $this->refreshStatus($budget->fresh(), $account);

                return $budget->fresh();
            });

        return $this->formatSnapshot($budgets->all());
    }

    public function snapshot(ConnectorAccount $account, ?ConnectorDataset $dataset = null): array
    {
        unset($dataset);

        return $this->formatSnapshot($this->ensureBudgets($account));
    }

    /**
     * @return list<ConnectorQuotaBudget>
     */
    private function ensureBudgets(ConnectorAccount $account): array
    {
        $quota = (array) data_get($this->registry->provider($account->provider_key), 'config_json.quota', []);
        $budgets = [];

        foreach ([ConnectorQuotaBudget::TYPE_HOURLY, ConnectorQuotaBudget::TYPE_DAILY] as $type) {
            $definition = (array) ($quota[$type] ?? []);
            $limit = (int) ($definition['limit'] ?? 0);

            if ($limit <= 0) {
                continue;
            }

            foreach ([null, $account->id] as $accountId) {
                $period = $this->period($type);
                $budget = ConnectorQuotaBudget::query()->firstOrCreate(
                    [
                        'workspace_id' => $account->workspace_id,
                        'connector_account_id' => $accountId,
                        'provider_key' => $account->provider_key,
                        'budget_type' => $type,
                        'period_started_at' => $period['start'],
                    ],
                    [
                        'limit_value' => $limit,
                        'used_value' => 0,
                        'warning_threshold_percent' => (int) ($definition['warning_threshold_percent'] ?? 80),
                        'status' => ConnectorQuotaBudget::STATUS_OK,
                        'period_ends_at' => $period['end'],
                        'reset_at' => $period['end'],
                        'metadata_json' => [
                            'scope' => $accountId === null ? 'workspace' : 'connector_account',
                        ],
                    ],
                );

                $this->refreshStatus($budget, $account);
                $budgets[] = $budget->fresh();
            }
        }

        return $budgets;
    }

    private function refreshStatus(ConnectorQuotaBudget $budget, ConnectorAccount $account): void
    {
        $limit = max(1, (int) $budget->limit_value);
        $used = max(0, (int) $budget->used_value);
        $percent = ($used / $limit) * 100;
        $status = match (true) {
            $used >= $limit => ConnectorQuotaBudget::STATUS_HARD_STOP,
            $percent >= (int) $budget->warning_threshold_percent => ConnectorQuotaBudget::STATUS_SOFT_WARNING,
            default => ConnectorQuotaBudget::STATUS_OK,
        };

        if ($budget->status === $status) {
            return;
        }

        $budget->forceFill(['status' => $status])->save();

        if ($status === ConnectorQuotaBudget::STATUS_SOFT_WARNING) {
            $this->health->record(
                account: $account,
                severity: ConnectorHealthEvent::SEVERITY_WARNING,
                eventType: 'quota.soft_warning',
                message: 'Connector quota is approaching its configured budget.',
                context: ['budget_id' => $budget->id, 'budget_type' => $budget->budget_type, 'used' => $used, 'limit' => $limit],
            );
        }

        if ($status === ConnectorQuotaBudget::STATUS_HARD_STOP) {
            $this->health->record(
                account: $account,
                severity: ConnectorHealthEvent::SEVERITY_CRITICAL,
                eventType: 'quota.hard_stop',
                message: 'Connector quota budget is exhausted.',
                context: ['budget_id' => $budget->id, 'budget_type' => $budget->budget_type, 'used' => $used, 'limit' => $limit],
            );
        }
    }

    /**
     * @return array{start: Carbon, end: Carbon}
     */
    private function period(string $type): array
    {
        $start = $type === ConnectorQuotaBudget::TYPE_HOURLY
            ? now()->startOfHour()
            : now()->startOfDay();

        return [
            'start' => $start,
            'end' => $type === ConnectorQuotaBudget::TYPE_HOURLY
                ? $start->copy()->addHour()
                : $start->copy()->addDay(),
        ];
    }

    /**
     * @param list<ConnectorQuotaBudget> $budgets
     * @return array<string, mixed>
     */
    private function formatSnapshot(array $budgets): array
    {
        return [
            'status' => collect($budgets)->contains(fn (ConnectorQuotaBudget $budget): bool => $budget->status === ConnectorQuotaBudget::STATUS_HARD_STOP)
                ? ConnectorQuotaBudget::STATUS_HARD_STOP
                : (collect($budgets)->contains(fn (ConnectorQuotaBudget $budget): bool => $budget->status === ConnectorQuotaBudget::STATUS_SOFT_WARNING)
                    ? ConnectorQuotaBudget::STATUS_SOFT_WARNING
                    : ConnectorQuotaBudget::STATUS_OK),
            'budgets' => collect($budgets)
                ->map(fn (ConnectorQuotaBudget $budget): array => [
                    'id' => $budget->id,
                    'scope' => $budget->connector_account_id === null ? 'workspace' : 'connector_account',
                    'type' => $budget->budget_type,
                    'limit' => $budget->limit_value,
                    'used' => $budget->used_value,
                    'remaining' => max(0, (int) $budget->limit_value - (int) $budget->used_value),
                    'status' => $budget->status,
                    'reset_at' => $budget->reset_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }
}
