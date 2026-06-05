<?php

namespace App\Jobs;

use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\Draft;
use App\Services\Publication\ContentPublicationService;
use RuntimeException;

/**
 * @deprecated Use PublishContentJob.
 */
class PublishToWordPressJob extends PublishContentJob
{
    public function __construct(string $contentId, ?string $publicationId = null)
    {
        parent::__construct($contentId, $publicationId);
    }

    public function uniqueId(): string
    {
        return 'publish_to_wp:' . ($this->publicationId ?: $this->contentId);
    }

    protected function resolveQueuedPublicationId(ContentPublicationService $publicationService): string
    {
        $publicationId = trim((string) ($this->publicationId ?? ''));

        if ($publicationId !== '') {
            $this->publicationId = $publicationId;

            return $publicationId;
        }

        $contentId = trim((string) $this->contentId);
        if ($contentId === '') {
            throw new RuntimeException('Legacy PublishToWordPressJob payload is missing both publicationId and contentId.');
        }

        $content = Content::query()->find($contentId);
        if (! $content) {
            throw new RuntimeException("PublishToWordPressJob could not resolve content [{$contentId}] for legacy queue payload.");
        }

        $draft = $this->draftId
            ? Draft::query()->find($this->draftId)
            : null;

        $publication = $publicationService->prepareWordPressPublication($content, $draft, [
            'source' => 'legacy_publish_to_wordpress_job',
        ]);

        if (! $publication) {
            throw new RuntimeException(
                "PublishToWordPressJob could not resolve a canonical publication for content [{$contentId}]. Requeue the publish flow with a publicationId."
            );
        }

        $this->publicationId = (string) $publication->id;

        return $this->publicationId;
    }
}
