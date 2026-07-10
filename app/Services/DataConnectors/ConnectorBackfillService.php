<?php

namespace App\Services\DataConnectors;

use App\Jobs\Connectors\RunConnectorBackfillRangeJob;
use App\Models\Connectors\ConnectorBackfillRange;
use App\Models\Connectors\ConnectorDataset;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class ConnectorBackfillService
{
    /**
     * @return Collection<int, ConnectorBackfillRange>
     */
    public function request(
        ConnectorDataset $dataset,
        CarbonInterface|string $start,
        CarbonInterface|string $end,
        ?User $requestedBy = null,
        ?int $chunkDays = null,
        bool $dispatch = true,
    ): Collection {
        $ranges = $this->createRanges($dataset, $start, $end, $requestedBy, $chunkDays);

        if ($dispatch) {
            $ranges->each(fn (ConnectorBackfillRange $range): mixed => RunConnectorBackfillRangeJob::dispatch((string) $range->id));
        }

        return $ranges;
    }

    /**
     * @return Collection<int, ConnectorBackfillRange>
     */
    public function createRanges(
        ConnectorDataset $dataset,
        CarbonInterface|string $start,
        CarbonInterface|string $end,
        ?User $requestedBy = null,
        ?int $chunkDays = null,
    ): Collection {
        $dataset->loadMissing('account');
        $startDate = $this->date($start)->startOfDay();
        $endDate = $this->date($end)->startOfDay();
        $chunkDays = max(1, $chunkDays ?? (int) config('data_connectors.backfills.default_chunk_days', 7));
        $ranges = collect();

        $this->guardBackfillRequest($startDate, $endDate, $chunkDays);

        for ($cursor = $startDate->copy(); $cursor->lte($endDate); $cursor->addDays($chunkDays)) {
            $rangeStart = $cursor->copy();
            $rangeEnd = $cursor->copy()->addDays($chunkDays - 1)->min($endDate);
            $idempotencyKey = $this->idempotencyKey($dataset, $rangeStart, $rangeEnd);

            $ranges->push(ConnectorBackfillRange::query()->firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'workspace_id' => $dataset->workspace_id,
                    'connector_account_id' => $dataset->connector_account_id,
                    'connector_dataset_id' => $dataset->id,
                    'requested_by_user_id' => $requestedBy?->id,
                    'provider_key' => $dataset->provider_key,
                    'dataset_key' => $dataset->dataset_key,
                    'status' => ConnectorBackfillRange::STATUS_PENDING,
                    'range_start' => $rangeStart->toDateString(),
                    'range_end' => $rangeEnd->toDateString(),
                    'attempts' => 0,
                    'metadata_json' => [
                        'duplicate_prevention' => 'connector_raw_records.fingerprint',
                        'requested_days' => $this->inclusiveDays($startDate, $endDate),
                        'chunk_days' => $chunkDays,
                        'raw_only' => true,
                    ],
                ],
            ));
        }

        return $ranges;
    }

    /**
     * @return Collection<int, ConnectorBackfillRange>
     */
    public function retryFailed(ConnectorDataset $dataset, bool $dispatch = true): Collection
    {
        $ranges = ConnectorBackfillRange::query()
            ->where('connector_dataset_id', $dataset->id)
            ->where('status', ConnectorBackfillRange::STATUS_FAILED)
            ->get();

        $ranges->each(function (ConnectorBackfillRange $range) use ($dispatch): void {
            $range->forceFill([
                'status' => ConnectorBackfillRange::STATUS_PENDING,
                'last_error' => null,
            ])->save();

            if ($dispatch) {
                RunConnectorBackfillRangeJob::dispatch((string) $range->id);
            }
        });

        return $ranges;
    }

    private function idempotencyKey(ConnectorDataset $dataset, CarbonInterface $start, CarbonInterface $end): string
    {
        return hash('sha256', implode('|', [
            $dataset->workspace_id,
            $dataset->connector_account_id,
            $dataset->id,
            $dataset->provider_key,
            $dataset->dataset_key,
            $start->toDateString(),
            $end->toDateString(),
        ]));
    }

    private function date(CarbonInterface|string $date): Carbon
    {
        return $date instanceof CarbonInterface ? Carbon::instance($date) : Carbon::parse($date);
    }

    private function guardBackfillRequest(Carbon $startDate, Carbon $endDate, int $chunkDays): void
    {
        if ($endDate->lt($startDate)) {
            throw new InvalidArgumentException('Connector backfill end date must be on or after the start date.');
        }

        $maxChunkDays = max(1, (int) config('data_connectors.backfills.max_chunk_days', 30));
        if ($chunkDays > $maxChunkDays) {
            throw new InvalidArgumentException("Connector backfill chunks may not exceed {$maxChunkDays} day(s).");
        }

        $requestedDays = $this->inclusiveDays($startDate, $endDate);
        $maxRequestedDays = max(1, (int) config('data_connectors.backfills.max_requested_days', 90));
        if ($requestedDays > $maxRequestedDays) {
            throw new InvalidArgumentException("Connector backfill request covers {$requestedDays} day(s), which exceeds the configured {$maxRequestedDays} day limit.");
        }

        $rangeCount = (int) ceil($requestedDays / max(1, $chunkDays));
        $maxRanges = max(1, (int) config('data_connectors.backfills.max_ranges_per_request', 30));
        if ($rangeCount > $maxRanges) {
            throw new InvalidArgumentException("Connector backfill request would create {$rangeCount} range(s), which exceeds the configured {$maxRanges} range limit.");
        }
    }

    private function inclusiveDays(CarbonInterface $startDate, CarbonInterface $endDate): int
    {
        return ((int) $startDate->diffInDays($endDate)) + 1;
    }
}
