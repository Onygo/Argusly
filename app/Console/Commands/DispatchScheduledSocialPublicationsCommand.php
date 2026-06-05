<?php

namespace App\Console\Commands;

use App\Enums\SocialPublicationStatus;
use App\Jobs\SocialDistribution\PublishSocialPostJob;
use App\Models\SocialPublication;
use App\Services\SocialDistribution\SocialDistributionAuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class DispatchScheduledSocialPublicationsCommand extends Command
{
    protected $signature = 'social:dispatch-scheduled-publications {--limit=50}';

    protected $description = 'Dispatch due scheduled social publications.';

    public function handle(SocialDistributionAuditLogger $audit): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $legacyWallClockCutoff = CarbonImmutable::now('Europe/Amsterdam')->format('Y-m-d H:i:s');

        $publicationIds = SocialPublication::query()
            ->whereNull('remote_post_id')
            ->where(function ($query) use ($legacyWallClockCutoff): void {
                $query->where(function ($scheduled): void {
                    $scheduled
                        ->where('status', SocialPublicationStatus::SCHEDULED->value)
                        ->whereNotNull('scheduled_for')
                        ->where('scheduled_for', '<=', now());
                })->orWhere(function ($legacyScheduled) use ($legacyWallClockCutoff): void {
                    $legacyScheduled
                        ->where('status', SocialPublicationStatus::SCHEDULED->value)
                        ->whereNotNull('scheduled_for')
                        ->where('scheduled_for', '<=', $legacyWallClockCutoff);
                })->orWhere(function ($queued): void {
                    $queued
                        ->where('status', SocialPublicationStatus::QUEUED->value)
                        ->where(function ($due): void {
                            $due->whereNull('scheduled_for')
                                ->orWhere('scheduled_for', '<=', now());
                        });
                })->orWhere(function ($rateLimited): void {
                    $rateLimited
                        ->where('status', SocialPublicationStatus::RATE_LIMITED->value)
                        ->whereNotNull('next_retry_at')
                        ->where('next_retry_at', '<=', now());
                });
            })
            ->orderByRaw('COALESCE(next_retry_at, scheduled_for, created_at)')
            ->limit($limit)
            ->pluck('id')
            ->all();

        $dispatched = 0;

        foreach ($publicationIds as $publicationId) {
            $publication = SocialPublication::query()->find((string) $publicationId);
            if (! $publication || $publication->remote_post_id) {
                continue;
            }

            if (! $this->publicationIsDue($publication)) {
                continue;
            }

            $before = $publication->attributesToArray();
            $publication->forceFill([
                'status' => SocialPublicationStatus::QUEUED->value,
                'queued_at' => $publication->queued_at ?: now(),
                'next_retry_at' => null,
            ])->save();

            $audit->record($publication, 'publication.queued_by_scheduler', $before, $publication->attributesToArray());
            PublishSocialPostJob::dispatch((string) $publication->id);
            $dispatched++;
        }

        $this->info(sprintf(
            'Processed %d due social publication(s). Dispatched %d publish job(s).',
            count($publicationIds),
            $dispatched,
        ));

        return self::SUCCESS;
    }

    private function publicationIsDue(SocialPublication $publication): bool
    {
        $status = $publication->status?->value ?? (string) $publication->status;

        if ($status === SocialPublicationStatus::RATE_LIMITED->value) {
            return $publication->next_retry_at !== null && ! $publication->next_retry_at->isFuture();
        }

        if ($publication->scheduled_for === null) {
            return $status === SocialPublicationStatus::QUEUED->value;
        }

        if (filled(data_get($publication->metadata, 'scheduled_timezone'))) {
            return ! $publication->scheduled_for->isFuture();
        }

        return $publication->scheduled_for->format('Y-m-d H:i:s') <= CarbonImmutable::now('Europe/Amsterdam')->format('Y-m-d H:i:s');
    }
}
