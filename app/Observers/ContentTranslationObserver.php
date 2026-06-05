<?php

namespace App\Observers;

use App\Models\ContentTranslation;
use App\Services\Performance\PerformanceCacheService;

class ContentTranslationObserver
{
    public function saved(ContentTranslation $translation): void
    {
        if (! $translation->wasRecentlyCreated && ! $translation->wasChanged([
            'status',
            'target_content_id',
            'target_locale',
            'failure_reason',
            'processing_job_uuid',
            'processing_failed_at',
            'processing_last_heartbeat_at',
            'processing_last_recovered_at',
            'error_message',
            'updated_at',
        ])) {
            return;
        }

        app(PerformanceCacheService::class)->bustForTranslation(
            $translation->loadMissing('content.workspace:id,organization_id')
        );
    }

    public function deleted(ContentTranslation $translation): void
    {
        app(PerformanceCacheService::class)->bustForTranslation(
            $translation->loadMissing('content.workspace:id,organization_id')
        );
    }
}
