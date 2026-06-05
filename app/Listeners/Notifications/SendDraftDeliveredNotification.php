<?php

namespace App\Listeners\Notifications;

use App\Events\Onboarding\ContentPushedToWordPress;
use App\Models\Draft;
use App\Models\Notification;
use App\Services\Notifications\NotificationService;

class SendDraftDeliveredNotification
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function handle(ContentPushedToWordPress $event): void
    {
        $draft = Draft::query()
            ->with(['clientSite.workspace', 'content'])
            ->find($event->draftId);

        $workspaceId = (string) ($draft?->clientSite?->workspace_id ?? '');
        if ($workspaceId === '') {
            return;
        }

        $ctaUrl = null;
        if ($draft?->content_id) {
            $ctaUrl = route('app.content.show', ['content' => $draft->content_id, 'tab' => 'overview']);
        }

        $this->notifications->notifyWorkspace(
            workspaceId: $workspaceId,
            type: Notification::TYPE_SYSTEM,
            title: 'Draft delivered to site',
            body: trim(sprintf('%s was delivered successfully.', (string) ($draft->title ?: 'A draft'))),
            options: [
                'cta_label' => $ctaUrl ? 'Open content' : null,
                'cta_url' => $ctaUrl,
                'dedupe_key' => 'draft_delivered:' . (string) $draft->id,
                'meta' => [
                    'draft_id' => (string) $draft->id,
                    'content_id' => (string) ($draft->content_id ?? ''),
                    'source' => 'event.content_pushed_to_wordpress',
                ],
            ]
        );
    }
}
