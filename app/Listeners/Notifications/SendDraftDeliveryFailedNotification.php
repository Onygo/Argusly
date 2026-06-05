<?php

namespace App\Listeners\Notifications;

use App\Events\Notifications\DraftDeliveryFailed;
use App\Models\Draft;
use App\Models\Notification;
use App\Services\Notifications\NotificationService;

class SendDraftDeliveryFailedNotification
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function handle(DraftDeliveryFailed $event): void
    {
        $draft = Draft::query()
            ->with(['clientSite.workspace', 'content.workspace'])
            ->find($event->draftId);

        $workspaceId = (string) (
            $draft?->clientSite?->workspace_id
            ?? $draft?->content?->workspace_id
            ?? ''
        );
        if ($workspaceId === '') {
            return;
        }

        $error = trim($event->error);
        $dedupeKey = 'draft_delivery_failed:' . (string) $draft->id . ':' . md5($error);

        $ctaUrl = null;
        if ($draft?->content_id) {
            $ctaUrl = route('app.content.show', ['content' => $draft->content_id, 'tab' => 'overview']);
        }

        $this->notifications->notifyWorkspace(
            workspaceId: $workspaceId,
            type: Notification::TYPE_ACTION_REQUIRED,
            title: 'Draft delivery failed',
            body: trim(sprintf(
                '%s failed to deliver. %s',
                (string) ($draft->title ?: 'A draft'),
                $error !== '' ? $error : 'Check connector and webhook settings.'
            )),
            options: [
                'cta_label' => $ctaUrl ? 'Open content' : null,
                'cta_url' => $ctaUrl,
                'dedupe_key' => $dedupeKey,
                'meta' => [
                    'draft_id' => (string) $draft->id,
                    'content_id' => (string) ($draft->content_id ?? ''),
                    'error' => $error,
                    'source' => 'event.draft_delivery_failed',
                ],
            ]
        );

        $adminCtaUrl = route('admin.workspaces.notifications', ['workspace' => $workspaceId]);

        $this->notifications->notifyAdmin(
            type: Notification::TYPE_ACTION_REQUIRED,
            title: 'Draft delivery failed for workspace',
            body: trim(sprintf(
                '%s failed delivery in workspace %s. %s',
                (string) ($draft->title ?: 'Draft'),
                $workspaceId,
                $error !== '' ? $error : 'Check connector and webhook settings.'
            )),
            options: [
                'workspace_id' => $workspaceId,
                'cta_label' => 'Open workspace notifications',
                'cta_url' => $adminCtaUrl,
                'dedupe_key' => 'admin:' . $dedupeKey,
                'meta' => [
                    'workspace_id' => $workspaceId,
                    'site_id' => (string) ($draft->client_site_id ?? ''),
                    'content_id' => (string) ($draft->content_id ?? ''),
                    'draft_id' => (string) $draft->id,
                    'error' => $error,
                    'source' => 'event.draft_delivery_failed',
                ],
            ]
        );
    }
}
