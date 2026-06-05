<?php

namespace App\Services\Integrations;

use App\Jobs\Integrations\DeliverApiWebhookJob;
use App\Models\ApiWebhook;
use App\Models\Workspace;
use App\Support\Webhooks\WebhookEnvelope;
use App\Support\Webhooks\WebhookEventRegistry;
use App\Support\Webhooks\WebhookPayload;

/**
 * Service for publishing webhook events to subscribed endpoints.
 *
 * ## Phase 2 Refactor
 *
 * This publisher now supports both:
 * - New typed payloads via WebhookPayload interface
 * - Legacy array payloads for backwards compatibility
 *
 * New code should use `publishPayload()` with a typed payload.
 * Legacy `publish()` method is maintained for existing code.
 */
class ApiWebhookPublisher
{
    /**
     * Publish a typed webhook payload.
     *
     * This is the preferred method for new code. It uses the WebhookPayload
     * interface to ensure consistent payload structure and versioning.
     */
    public function publishPayload(
        Workspace $workspace,
        WebhookPayload $payload,
        ?string $eventId = null,
    ): void {
        $envelope = WebhookEnvelope::fromPayload(
            payload: $payload,
            workspaceId: $workspace->id,
            eventId: $eventId,
        );

        $contentDestinationId = $payload->meta()['content_destination_id'] ?? null;

        $this->dispatchToWebhooks(
            workspace: $workspace,
            eventType: $payload->eventType(),
            envelopeData: $envelope->toArray(),
            contentDestinationId: $contentDestinationId,
            eventId: $envelope->eventId(),
            eventVersion: $envelope->eventVersion(),
        );

        // Also dispatch to legacy event name if this is a replacement event
        $this->dispatchLegacyEquivalent($workspace, $payload, $envelope, $contentDestinationId);
    }

    /**
     * Legacy publish method - maintains backwards compatibility.
     *
     * @deprecated Use publishPayload() with a typed WebhookPayload instead.
     *
     * @param array<string, mixed> $payload
     */
    public function publish(
        Workspace $workspace,
        string $eventType,
        array $payload,
        ?string $contentDestinationId = null,
        ?string $eventId = null,
    ): void {
        // Wrap in envelope for consistency
        $envelope = new WebhookEnvelope(
            event: $eventType,
            data: $payload,
            workspaceId: $workspace->id,
            eventId: $eventId,
        );

        $this->dispatchToWebhooks(
            workspace: $workspace,
            eventType: $eventType,
            envelopeData: $envelope->toArray(),
            contentDestinationId: $contentDestinationId,
            eventId: $envelope->eventId(),
            eventVersion: $envelope->eventVersion(),
        );
    }

    /**
     * Dispatch webhook jobs to all matching webhooks.
     *
     * @param array<string, mixed> $envelopeData
     */
    private function dispatchToWebhooks(
        Workspace $workspace,
        string $eventType,
        array $envelopeData,
        ?string $contentDestinationId,
        string $eventId,
        string $eventVersion,
    ): void {
        $webhooks = ApiWebhook::query()
            ->where('workspace_id', $workspace->id)
            ->where('is_active', true)
            ->when($contentDestinationId !== null, function ($query) use ($contentDestinationId): void {
                $query->where(function ($nested) use ($contentDestinationId): void {
                    $nested->whereNull('content_destination_id')
                        ->orWhere('content_destination_id', $contentDestinationId);
                });
            })
            ->get();

        foreach ($webhooks as $webhook) {
            if (! $webhook->subscribesTo($eventType)) {
                continue;
            }

            DeliverApiWebhookJob::dispatch(
                webhookId: (string) $webhook->id,
                eventType: $eventType,
                payload: $envelopeData,
                eventId: $eventId,
                eventVersion: $eventVersion,
            )->onQueue((string) config('publishlayer.webhooks.queue', 'deliveries'));
        }
    }

    /**
     * Dispatch to legacy event name if applicable.
     *
     * For example, when publishing draft.generation.succeeded,
     * also dispatch to draft.generation.completed for backwards compatibility.
     */
    private function dispatchLegacyEquivalent(
        Workspace $workspace,
        WebhookPayload $payload,
        WebhookEnvelope $envelope,
        ?string $contentDestinationId,
    ): void {
        $legacyEvent = $this->getLegacyEventName($payload->eventType());

        if ($legacyEvent === null) {
            return;
        }

        // Create a legacy-format envelope
        $legacyEnvelope = new WebhookEnvelope(
            event: $legacyEvent,
            data: $payload->toArray(),
            workspaceId: $workspace->id,
            eventId: $envelope->eventId() . '_legacy',
        );

        $this->dispatchToWebhooks(
            workspace: $workspace,
            eventType: $legacyEvent,
            envelopeData: $legacyEnvelope->toArray(),
            contentDestinationId: $contentDestinationId,
            eventId: $legacyEnvelope->eventId(),
            eventVersion: $legacyEnvelope->eventVersion(),
        );
    }

    /**
     * Map new event names to their legacy equivalents.
     */
    private function getLegacyEventName(string $eventType): ?string
    {
        return match ($eventType) {
            WebhookEventRegistry::DRAFT_GENERATION_SUCCEEDED => WebhookEventRegistry::LEGACY_DRAFT_GENERATION_COMPLETED,
            WebhookEventRegistry::DRAFT_TRANSLATION_SUCCEEDED => WebhookEventRegistry::LEGACY_DRAFT_TRANSLATED,
            WebhookEventRegistry::ARTICLE_CREATED => WebhookEventRegistry::LEGACY_BRIEF_CREATED,
            default => null,
        };
    }
}
