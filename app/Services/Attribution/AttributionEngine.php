<?php

namespace App\Services\Attribution;

use App\Models\AttributionConversion;
use App\Models\AttributionModelConfiguration;
use App\Models\AttributionResult;
use App\Models\AttributionRun;
use App\Models\AttributionTouchpoint;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class AttributionEngine
{
    public function __construct(
        private readonly AttributionModelRegistry $models,
        private readonly AttributionConfigurationResolver $configurations,
        private readonly AttributionIdentityMatcher $matcher,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(
        Workspace|string $workspace,
        string|AttributionModelConfiguration $configuration = 'last_touch',
        Carbon|string|null $periodStart = null,
        Carbon|string|null $periodEnd = null,
        array $options = [],
    ): AttributionRun {
        $workspaceId = $workspace instanceof Workspace ? (string) $workspace->id : (string) $workspace;
        $configuration = $configuration instanceof AttributionModelConfiguration
            ? $configuration
            : $this->configurations->resolve($workspaceId, $configuration);

        $model = $this->models->resolve($configuration->model_key);
        $periodStart = $periodStart ? Carbon::parse($periodStart)->startOfDay() : now()->subDays(30)->startOfDay();
        $periodEnd = $periodEnd ? Carbon::parse($periodEnd)->endOfDay() : now()->endOfDay();

        $run = AttributionRun::query()->create([
            'workspace_id' => $workspaceId,
            'attribution_model_configuration_id' => $configuration->id,
            'model_key' => $model->key(),
            'status' => AttributionRun::STATUS_RUNNING,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'lookback_days' => (int) ($options['lookback_days'] ?? $configuration->lookback_days),
            'started_at' => now(),
            'metadata_json' => [
                'configuration_key' => $configuration->key,
                'settings' => array_merge((array) ($configuration->settings_json ?? []), $options),
            ],
        ]);

        $processed = 0;
        $matched = 0;
        $written = 0;

        try {
            AttributionConversion::query()
                ->forWorkspace($workspaceId)
                ->whereBetween('occurred_at', [$periodStart, $periodEnd])
                ->orderBy('occurred_at')
                ->chunkById(100, function ($conversions) use ($run, $configuration, $model, $options, &$processed, &$matched, &$written): void {
                    foreach ($conversions as $conversion) {
                        $processed++;

                        $matches = $this->matcher->matches(
                            $conversion,
                            (int) ($options['lookback_days'] ?? $configuration->lookback_days),
                            array_merge((array) ($configuration->settings_json ?? []), $options),
                        );

                        if ($matches->isEmpty()) {
                            $written += $this->writeUnmatched($run, $conversion);

                            continue;
                        }

                        $matched += $matches->count();
                        $allocations = $model->allocate(
                            $matches,
                            $conversion,
                            array_merge((array) ($configuration->settings_json ?? []), $options),
                        );

                        foreach ($allocations as $allocation) {
                            $written += $this->writeAllocation(
                                $run,
                                $conversion,
                                $allocation['touchpoint'],
                                (float) $allocation['credit'],
                                (array) $allocation['metadata'],
                            );
                        }
                    }
                });

            $run->forceFill([
                'status' => AttributionRun::STATUS_COMPLETED,
                'finished_at' => now(),
                'conversions_processed' => $processed,
                'touchpoints_matched' => $matched,
                'results_written' => $written,
            ])->save();
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => AttributionRun::STATUS_FAILED,
                'finished_at' => now(),
                'conversions_processed' => $processed,
                'touchpoints_matched' => $matched,
                'results_written' => $written,
                'latest_error' => $exception->getMessage(),
                'metadata_json' => array_merge((array) ($run->metadata_json ?? []), [
                    'error_class' => $exception::class,
                ]),
            ])->save();

            throw $exception;
        }

        return $run->fresh();
    }

    private function writeUnmatched(AttributionRun $run, AttributionConversion $conversion): int
    {
        return $this->upsertResult($run, $conversion, null, 0.0, [
            'strategy' => 'unmatched',
            'match_confidence' => AttributionIdentityMatcher::CONFIDENCE_UNMATCHED,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function writeAllocation(
        AttributionRun $run,
        AttributionConversion $conversion,
        AttributionTouchpoint $touchpoint,
        float $credit,
        array $metadata,
    ): int {
        return $this->upsertResult($run, $conversion, $touchpoint, $credit, $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function upsertResult(
        AttributionRun $run,
        AttributionConversion $conversion,
        ?AttributionTouchpoint $touchpoint,
        float $credit,
        array $metadata,
    ): int {
        $resultKey = hash('sha256', implode('|', [
            $run->id,
            $conversion->id,
            $touchpoint?->id ?: 'unmatched',
            $run->model_key,
        ]));

        $value = round(((float) ($conversion->value ?? 0)) * $credit, 6);
        $now = now();

        DB::table('attribution_results')->upsert([[
            'id' => (string) Str::uuid(),
            'workspace_id' => $run->workspace_id,
            'attribution_run_id' => $run->id,
            'attribution_touchpoint_id' => $touchpoint?->id,
            'attribution_conversion_id' => $conversion->id,
            'result_key' => $resultKey,
            'model_key' => $run->model_key,
            'credit' => $credit,
            'value' => $value,
            'currency' => $conversion->currency,
            'match_confidence' => (string) ($metadata['match_confidence'] ?? AttributionIdentityMatcher::CONFIDENCE_UNMATCHED),
            'metadata_json' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['result_key'], [
            'credit',
            'value',
            'currency',
            'match_confidence',
            'metadata_json',
            'updated_at',
        ]);

        return AttributionResult::query()->where('result_key', $resultKey)->exists() ? 1 : 0;
    }
}
