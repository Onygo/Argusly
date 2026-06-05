<?php

namespace App\Listeners\Notifications;

use App\Events\Notifications\SiteVerified;
use App\Models\ClientSite;
use App\Models\Notification;
use App\Services\Notifications\NotificationService;

class SendSiteVerifiedNotification
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function handle(SiteVerified $event): void
    {
        $site = ClientSite::query()->with('workspace')->find($event->siteId);
        $workspaceId = (string) ($site?->workspace_id ?? '');
        if ($workspaceId === '') {
            return;
        }

        $title = 'Site connection verified';
        $body = trim(sprintf(
            '%s is connected via %s.',
            (string) ($site->name ?: 'Site'),
            trim($event->channel) !== '' ? $event->channel : 'connector'
        ));

        $this->notifications->notifyWorkspace(
            workspaceId: $workspaceId,
            type: Notification::TYPE_SYSTEM,
            title: $title,
            body: $body,
            options: [
                'cta_label' => 'Open site',
                'cta_url' => route('app.sites.show', $site),
                'dedupe_key' => 'site_verified:' . (string) $site->id . ':' . now()->format('Ymd'),
                'meta' => [
                    'site_id' => (string) $site->id,
                    'channel' => $event->channel,
                    'source' => 'event.site_verified',
                ],
            ]
        );
    }
}
