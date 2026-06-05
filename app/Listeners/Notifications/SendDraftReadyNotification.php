<?php

namespace App\Listeners\Notifications;

use App\Events\Onboarding\DraftGenerated;
use App\Models\Draft;
use App\Models\Notification;
use App\Services\Notifications\NotificationService;

class SendDraftReadyNotification
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function handle(DraftGenerated $event): void
    {
        $draft = Draft::query()
            ->with(['clientSite.workspace', 'content'])
            ->find($event->draftId);

        $workspaceId = (string) ($draft?->clientSite?->workspace_id ?? '');
        if ($workspaceId === '') {
            return;
        }

        $title = 'Draft ready for review';
        $body = trim(sprintf(
            '%s is ready for review.',
            (string) ($draft->title ?: 'A draft')
        ));

        $ctaUrl = null;
        if ($draft?->content_id) {
            $ctaUrl = route('app.content.show', ['content' => $draft->content_id, 'tab' => 'draft']);
        }

        $this->notifications->notifyWorkspace(
            workspaceId: $workspaceId,
            type: Notification::TYPE_ACTION_REQUIRED,
            title: $title,
            body: $body,
            options: [
                'cta_label' => $ctaUrl ? 'Open draft' : null,
                'cta_url' => $ctaUrl,
                'dedupe_key' => 'draft_ready:' . (string) $draft->id,
                'meta' => [
                    'draft_id' => (string) $draft->id,
                    'content_id' => (string) ($draft->content_id ?? ''),
                    'source' => 'event.draft_generated',
                ],
            ]
        );
    }
}
