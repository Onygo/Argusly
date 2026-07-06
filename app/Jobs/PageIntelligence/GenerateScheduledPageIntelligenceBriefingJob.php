<?php

namespace App\Jobs\PageIntelligence;

use App\Contracts\PageIntelligence\ScheduledBriefingContract;
use App\Models\PageIntelligenceReport;
use App\Models\ScheduledPageIntelligenceBriefing;
use App\Services\PageIntelligence\Reports\PageIntelligenceReportDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class GenerateScheduledPageIntelligenceBriefingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public int $backoff = 60;

    public function __construct(
        public readonly string $scheduledBriefingId,
        public readonly ?string $schedulerClaimToken = null,
    ) {
        $this->onQueue((string) config('page_intelligence.queues.reports', 'page_intelligence_reports'));
    }

    public function handle(ScheduledBriefingContract $briefings): void
    {
        $schedule = $this->claimedSchedule();

        if (! $schedule instanceof ScheduledPageIntelligenceBriefing || ! $schedule->workspace) {
            return;
        }

        $runAt = $schedule->next_run_at?->copy() ?? now();
        if ($runAt->isFuture()) {
            $this->clearClaim($schedule);

            return;
        }

        try {
            $period = $schedule->reportPeriodForRun($runAt);
            $idempotencyKey = $schedule->idempotencyKeyForPeriod($period['period_start'], $period['period_end']);

            $report = $briefings->prepare($schedule->workspace, $schedule->report_type, [
                'client_site_id' => $schedule->client_site_id,
                'market_pack_key' => $schedule->market_pack_key,
                'period_start' => $period['period_start'],
                'period_end' => $period['period_end'],
                'idempotency_key' => $idempotencyKey,
            ], $schedule->createdBy);

            $this->attachScheduleMetadata($report, $schedule, $idempotencyKey);
            $deliverySummary = app(PageIntelligenceReportDeliveryService::class)->deliverScheduledReport($report->refresh(), $schedule);

            $generatedAt = now();
            ScheduledPageIntelligenceBriefing::query()
                ->whereKey($schedule->id)
                ->where('scheduler_claim_token', $this->schedulerClaimToken)
                ->update([
                'last_generated_at' => $generatedAt,
                'last_error' => null,
                'next_run_at' => $schedule->calculateNextRunAt($generatedAt),
                'scheduler_claimed_at' => null,
                'scheduler_claim_expires_at' => null,
                'scheduler_claim_token' => null,
                'delivery_state_json' => json_encode([
                    'status' => $deliverySummary['status'] ?? 'snapshot_generated',
                    'artifact_status' => $report->artifact_status,
                    'delivery_enabled' => true,
                    'email_sent' => false,
                    'channels' => $deliverySummary['channels'] ?? [],
                    'total' => $deliverySummary['total'] ?? 0,
                    'delivered' => $deliverySummary['delivered'] ?? 0,
                    'failed' => $deliverySummary['failed'] ?? 0,
                    'skipped' => $deliverySummary['skipped'] ?? 0,
                    'last_report_id' => $report->id,
                    'last_idempotency_key' => hash('sha256', $idempotencyKey),
                    'updated_at' => $generatedAt->toIso8601String(),
                ], JSON_THROW_ON_ERROR),
                'updated_at' => $generatedAt,
            ]);

            GeneratePageIntelligenceReportArtifactJob::dispatch((string) $report->id);
        } catch (Throwable $exception) {
            $this->recordFailure($schedule, $exception);

            throw $exception;
        }
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('page-intelligence:scheduled-briefing:'.$this->scheduledBriefingId))
                ->releaseAfter(60)
                ->expireAfter(600),
        ];
    }

    private function claimedSchedule(): ?ScheduledPageIntelligenceBriefing
    {
        if (! filled($this->schedulerClaimToken)) {
            return null;
        }

        return DB::transaction(function (): ?ScheduledPageIntelligenceBriefing {
            $schedule = ScheduledPageIntelligenceBriefing::query()
                ->with(['workspace', 'createdBy'])
                ->whereKey($this->scheduledBriefingId)
                ->where('is_active', true)
                ->where('scheduler_claim_token', $this->schedulerClaimToken)
                ->whereNotNull('scheduler_claim_expires_at')
                ->where('scheduler_claim_expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            return $schedule instanceof ScheduledPageIntelligenceBriefing ? $schedule : null;
        });
    }

    private function attachScheduleMetadata(PageIntelligenceReport $report, ScheduledPageIntelligenceBriefing $schedule, string $idempotencyKey): void
    {
        $payload = $report->payload_json ?? [];
        $provenance = $report->provenance_json ?? [];
        $schedulePayload = [
            'scheduled_page_intelligence_briefing_id' => $schedule->id,
            'frequency' => $schedule->frequency,
            'timezone' => $schedule->timezone,
            'delivery_enabled' => true,
            'delivery_channels' => $schedule->delivery_channels_json ?? [],
            'recipients_count' => count((array) ($schedule->recipients_json ?? [])),
            'idempotency_key_hash' => hash('sha256', $idempotencyKey),
        ];

        data_set($payload, 'scheduled_briefing', $schedulePayload);
        data_set($provenance, 'scheduled_briefing', $schedulePayload);

        $report->forceFill([
            'scheduled_page_intelligence_briefing_id' => $schedule->id,
            'payload_json' => $payload,
            'provenance_json' => $provenance,
        ])->save();
    }

    private function recordFailure(ScheduledPageIntelligenceBriefing $schedule, Throwable $exception): void
    {
        $failedAt = now();
        ScheduledPageIntelligenceBriefing::query()
            ->whereKey($schedule->id)
            ->where('scheduler_claim_token', $this->schedulerClaimToken)
            ->update([
            'last_failed_at' => $failedAt,
            'last_error' => mb_substr($exception->getMessage(), 0, 4000),
            'failure_count' => DB::raw('failure_count + 1'),
            'scheduler_claimed_at' => null,
            'scheduler_claim_expires_at' => null,
            'scheduler_claim_token' => null,
            'delivery_state_json' => json_encode(array_merge((array) ($schedule->delivery_state_json ?? []), [
                'status' => 'failed',
                'delivery_enabled' => false,
                'email_sent' => false,
                'last_error' => mb_substr($exception->getMessage(), 0, 4000),
                'failed_at' => $failedAt->toIso8601String(),
            ]), JSON_THROW_ON_ERROR),
            'updated_at' => $failedAt,
        ]);
    }

    private function clearClaim(ScheduledPageIntelligenceBriefing $schedule): void
    {
        if (! filled($this->schedulerClaimToken)) {
            return;
        }

        ScheduledPageIntelligenceBriefing::query()
            ->whereKey($schedule->id)
            ->where('scheduler_claim_token', $this->schedulerClaimToken)
            ->update([
                'scheduler_claimed_at' => null,
                'scheduler_claim_expires_at' => null,
                'scheduler_claim_token' => null,
                'updated_at' => now(),
            ]);
    }
}
