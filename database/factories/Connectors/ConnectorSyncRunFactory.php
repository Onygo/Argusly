<?php

namespace Database\Factories\Connectors;

use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorDataset;
use App\Models\Connectors\ConnectorSyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConnectorSyncRun>
 */
class ConnectorSyncRunFactory extends Factory
{
    protected $model = ConnectorSyncRun::class;

    public function definition(): array
    {
        $account = ConnectorAccount::query()->first() ?? ConnectorAccount::factory()->create();
        $dataset = ConnectorDataset::query()->where('connector_account_id', $account->id)->first();

        return [
            'connector_account_id' => $account->id,
            'connector_dataset_id' => $dataset?->id,
            'workspace_id' => $account->workspace_id,
            'client_site_id' => $account->client_site_id,
            'provider_key' => $account->provider_key,
            'dataset_key' => $dataset?->dataset_key,
            'status' => ConnectorSyncRun::STATUS_PENDING,
            'run_type' => ConnectorSyncRun::TYPE_MANUAL,
            'window_start' => null,
            'window_end' => null,
            'cursor_before_json' => null,
            'cursor_after_json' => null,
            'started_at' => null,
            'finished_at' => null,
            'duration_ms' => null,
            'records_processed' => 0,
            'attempts' => 0,
            'error_message' => null,
            'metrics_json' => [],
            'rate_limit_json' => [],
            'retry_json' => [],
            'next_retry_at' => null,
            'cancelled_at' => null,
            'idempotency_key' => fake()->uuid(),
        ];
    }
}
