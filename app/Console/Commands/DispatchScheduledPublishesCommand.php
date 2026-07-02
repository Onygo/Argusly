<?php

namespace App\Console\Commands;

use App\Enums\PublicationDeliveryStatus;
use App\Models\Content;
use App\Models\ContentPublication;
use App\Services\Integrations\LaravelConnectorDestinationResolver;
use App\Services\Integrations\LaravelConnectorPublishingService;
use App\Services\Publication\ContentPublicationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class DispatchScheduledPublishesCommand extends Command
{
    protected $signature = 'content:dispatch-scheduled-publishes {--limit=50} {--stale-after-minutes=15}';

    protected $description = 'Dispatch due scheduled publishes and recover stale publication jobs.';

    public function handle(
        ContentPublicationService $publicationService,
        LaravelConnectorDestinationResolver $laravelDestinationResolver,
        LaravelConnectorPublishingService $laravelPublishingService,
    ): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $staleAfterMinutes = max(1, (int) $this->option('stale-after-minutes'));

        $dueContentIds = Content::query()
            ->whereNotNull('scheduled_publish_at')
            ->where('scheduled_publish_at', '<=', now())
            ->where('publish_status', '!=', 'published')
            ->orderBy('scheduled_publish_at')
            ->limit($limit)
            ->pluck('id')
            ->all();

        $staleContentIds = ContentPublication::query()
            ->whereIn('provider', [
                ContentPublication::PROVIDER_WORDPRESS,
                ContentPublication::PROVIDER_LARAVEL,
            ])
            ->whereIn('delivery_status', [
                ContentPublication::STATUS_PENDING,
                PublicationDeliveryStatus::PROCESSING->value,
            ])
            ->where('updated_at', '<=', now()->subMinutes($staleAfterMinutes))
            ->whereHas('content', function ($query): void {
                $query->where(function ($contentQuery): void {
                    $contentQuery->where(function ($scheduledQuery): void {
                        $scheduledQuery->whereNotNull('scheduled_publish_at')
                            ->where('scheduled_publish_at', '<=', now());
                    })->orWhere('publish_status', 'publishing');
                });
            })
            ->limit($limit)
            ->pluck('content_id')
            ->all();

        $contentIds = collect($dueContentIds)
            ->merge($staleContentIds)
            ->unique()
            ->take($limit)
            ->values();

        $dispatched = 0;
        $skipped = 0;

        foreach ($contentIds as $contentId) {
            $content = Content::query()->find((string) $contentId);
            if (! $content) {
                continue;
            }

            try {
                if (
                    $content->clientSite
                    && strtolower(trim((string) $content->clientSite->type)) === 'laravel'
                    && ! $laravelDestinationResolver->resolveForContent($content)
                ) {
                    $laravelPublishingService->publish($content, null, 'scheduled_publish', 'console.dispatch_scheduled_publishes');
                    $dispatched++;

                    continue;
                }

                $dispatch = $publicationService->dispatchPublication($content, null, [
                    'source' => 'console.dispatch_scheduled_publishes',
                    'allow_stale_reclaim' => true,
                    'stale_after_minutes' => $staleAfterMinutes,
                ]);

                if ((bool) ($dispatch['queued'] ?? false)) {
                    $dispatched++;

                    continue;
                }
            } catch (Throwable $exception) {
                $content->refresh();

                if (in_array((string) $content->publish_status, ['scheduled', 'publishing', 'queued', 'processing'], true)) {
                    $content->forceFill([
                        'publish_status' => 'failed',
                        'publish_error' => $exception->getMessage() !== ''
                            ? $exception->getMessage()
                            : 'Scheduled publication failed.',
                    ])->save();
                }

                Log::error('content.scheduled_publish_dispatch_failed', [
                    'content_id' => (string) $content->id,
                    'scheduled_publish_at' => $content->scheduled_publish_at?->toIso8601String(),
                    'publish_status' => (string) $content->publish_status,
                    'error' => $exception->getMessage(),
                    'exception' => $exception::class,
                    'throwable' => $exception,
                ]);
            }

            $skipped++;
        }

        $this->info(sprintf(
            'Processed %d scheduled/stale items. Dispatched %d publication flow(s), skipped %d.',
            $contentIds->count(),
            $dispatched,
            $skipped,
        ));

        return self::SUCCESS;
    }
}
