<?php

namespace App\Support\Webhooks\Payloads;

use App\Models\Workspace;
use App\Support\Webhooks\AbstractWebhookPayload;
use App\Support\Webhooks\WebhookEventRegistry;

/**
 * Payload builder for system events.
 *
 * Used for:
 * - credits.low
 */
class SystemPayload extends AbstractWebhookPayload
{
    public function __construct(
        private Workspace $workspace,
        private string $eventType,
        private array $data = [],
    ) {}

    public static function creditsLow(
        Workspace $workspace,
        int $currentCredits,
        int $thresholdCredits,
        ?int $percentageRemaining = null,
    ): self {
        return new self(
            workspace: $workspace,
            eventType: WebhookEventRegistry::CREDITS_LOW,
            data: [
                'current_credits' => $currentCredits,
                'threshold_credits' => $thresholdCredits,
                'percentage_remaining' => $percentageRemaining,
            ],
        );
    }

    public function eventType(): string
    {
        return $this->eventType;
    }

    public function toArray(): array
    {
        return array_merge([
            'workspace_id' => $this->workspace->id,
            'workspace_name' => $this->workspace->name,
            'organization_id' => $this->workspace->organization_id,
            'timestamp' => $this->formatDateTime(now()),
        ], $this->data);
    }

    public function links(): array
    {
        return [
            'workspace' => $this->buildApiLink("headless/workspace"),
            'billing' => $this->buildApiLink("headless/billing"),
        ];
    }

    public function meta(): array
    {
        return [];
    }
}
