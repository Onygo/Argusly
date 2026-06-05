<?php

namespace App\Listeners\ContentAutomation;

use App\Events\Agents\TranslationCompleted;
use App\Models\Content;
use App\Services\ContentAutomation\AutomationRunItemStateService;

class SyncAutomationTranslationResult
{
    public function handle(TranslationCompleted $event): void
    {
        $contentId = trim((string) ($event->translatedContentId ?? ''));
        if ($contentId === '') {
            return;
        }

        $content = Content::query()
            ->with(['drafts' => fn ($query) => $query->latest('created_at'), 'publications'])
            ->find($contentId);

        if ($content instanceof Content) {
            app(AutomationRunItemStateService::class)->syncFromContent($content);
        }
    }
}
