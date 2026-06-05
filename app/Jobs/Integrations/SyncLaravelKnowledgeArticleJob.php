<?php

namespace App\Jobs\Integrations;

use App\Models\Content;
use App\Models\ContentPublishTarget;
use App\Services\Integrations\LaravelConnectorDestinationResolver;
use App\Services\Integrations\LaravelConnectorPermanentSyncException;
use App\Services\Integrations\LaravelConnectorPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncLaravelKnowledgeArticleJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 4;

    public int $timeout = 30;

    public function __construct(
        public string $contentId,
        public string $triggerSource = 'manual',
        public ?string $articleStatus = null,
    ) {}

    public function backoff(): array
    {
        return [30, 120, 300, 900];
    }

    public function handle(
        LaravelConnectorDestinationResolver $destinationResolver,
        LaravelConnectorPublisher $publisher,
    ): void {
        $content = Content::query()
            ->with(['contentDestination', 'clientSite'])
            ->find($this->contentId);

        if (! $content) {
            return;
        }

        $destination = $destinationResolver->resolveForContent($content);
        if (! $destination) {
            return;
        }

        $publishTarget = ContentPublishTarget::query()
            ->where('content_id', (string) $content->id)
            ->where('content_destination_id', (string) $destination->id)
            ->where('target_type', 'laravel_connector')
            ->latest('created_at')
            ->first();

        if (! $publishTarget) {
            return;
        }

        try {
            $publisher->publishKnowledgeArticle(
                destination: $destination,
                content: $content,
                publishTarget: $publishTarget,
                triggerSource: $this->triggerSource,
                attempt: max(1, $this->attempts()),
                articleStatus: $this->articleStatus,
            );
        } catch (LaravelConnectorPermanentSyncException $exception) {
            $this->fail($exception);

            return;
        }
    }
}
