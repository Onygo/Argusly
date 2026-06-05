<?php

namespace App\Listeners\Content;

use App\Events\Agents\TranslationCompleted;
use App\Models\Content;
use App\Services\Content\LocalePublishingSyncService;

class SyncLinkedLocalePublishingAfterTranslation
{
    public function __construct(
        private readonly LocalePublishingSyncService $syncService,
    ) {}

    public function handle(TranslationCompleted $event): void
    {
        if (! $event->translatedContentId) {
            return;
        }

        $content = Content::query()->find($event->translatedContentId);
        if (! $content) {
            return;
        }

        $this->syncService->syncReadyTranslation($content);
    }
}
