<?php

namespace App\Support\Webhooks;

use Illuminate\Support\Str;

/**
 * Standard webhook envelope that wraps all outgoing webhook payloads.
 *
 * ## Envelope Structure
 *
 * ```json
 * {
 *     "event": "article.created",
 *     "event_version": "2026-03-21",
 *     "event_id": "evt_01HXY...",
 *     "sent_at": "2026-03-21T12:34:56.123456Z",
 *     "workspace_id": "ws_01HXY...",
 *     "data": { ... payload ... },
 *     "links": {
 *         "self": "https://app.publishlayer.com/api/v1/articles/..."
 *     }
 * }
 * ```
 *
 * ## Event ID Strategy
 *
 * Event IDs are globally unique and safe for consumer deduplication:
 * - Format: `evt_{ulid}_{fingerprint}`
 * - ULID provides time-ordering and uniqueness
 * - Fingerprint derived from event type + resource ID for idempotency
 *
 * ## Versioning
 *
 * The `event_version` field indicates the payload structure version.
 * Consumers should:
 * - Check version before processing
 * - Handle unknown fields gracefully
 * - Support multiple versions during migration
 */
class WebhookEnvelope
{
    private string $event;
    private string $eventVersion;
    private string $eventId;
    private string $sentAt;
    private ?string $workspaceId;
    private array $data;
    private array $links;
    private array $meta;

    public function __construct(
        string $event,
        array $data,
        ?string $workspaceId = null,
        ?string $eventId = null,
        ?string $eventVersion = null,
        array $links = [],
        array $meta = [],
    ) {
        $this->event = $event;
        $this->eventVersion = $eventVersion ?? WebhookEventRegistry::CURRENT_VERSION;
        $this->eventId = $eventId ?? $this->generateEventId($event, $data);
        $this->sentAt = now()->format('Y-m-d\TH:i:s.u\Z');
        $this->workspaceId = $workspaceId;
        $this->data = $data;
        $this->links = $links;
        $this->meta = $meta;
    }

    /**
     * Create an envelope from a payload builder.
     */
    public static function fromPayload(
        WebhookPayload $payload,
        ?string $workspaceId = null,
        ?string $eventId = null,
    ): self {
        return new self(
            event: $payload->eventType(),
            data: $payload->toArray(),
            workspaceId: $workspaceId,
            eventId: $eventId,
            eventVersion: $payload->version(),
            links: $payload->links(),
            meta: $payload->meta(),
        );
    }

    /**
     * Generate a unique, deduplication-safe event ID.
     *
     * Format: evt_{ulid}_{fingerprint}
     *
     * The fingerprint is derived from:
     * - Event type
     * - Primary resource ID (if present in data)
     * - Timestamp (seconds precision)
     *
     * This allows consumers to deduplicate events that might be
     * delivered multiple times due to retries.
     */
    private function generateEventId(string $event, array $data): string
    {
        $ulid = Str::ulid()->toBase32();

        // Extract primary resource ID for fingerprint
        $resourceId = $data['id']
            ?? $data['article_id']
            ?? $data['content_id']
            ?? $data['draft_id']
            ?? $data['publication_id']
            ?? '';

        // Create fingerprint from event + resource + timestamp (minute precision)
        $fingerprint = substr(
            hash('sha256', $event . $resourceId . floor(now()->timestamp / 60)),
            0,
            8
        );

        return "evt_{$ulid}_{$fingerprint}";
    }

    /**
     * Get the event type.
     */
    public function event(): string
    {
        return $this->event;
    }

    /**
     * Get the event ID.
     */
    public function eventId(): string
    {
        return $this->eventId;
    }

    /**
     * Get the event version.
     */
    public function eventVersion(): string
    {
        return $this->eventVersion;
    }

    /**
     * Check if the event is deprecated.
     */
    public function isDeprecated(): bool
    {
        return WebhookEventRegistry::isDeprecated($this->event);
    }

    /**
     * Convert the envelope to an array for JSON encoding.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $envelope = [
            'event' => $this->event,
            'event_version' => $this->eventVersion,
            'event_id' => $this->eventId,
            'sent_at' => $this->sentAt,
            'data' => $this->data,
        ];

        if ($this->workspaceId !== null) {
            $envelope['workspace_id'] = $this->workspaceId;
        }

        if ($this->links !== []) {
            $envelope['links'] = $this->links;
        }

        // Add deprecation notice if applicable
        if ($this->isDeprecated()) {
            $replacement = WebhookEventRegistry::getReplacementEvent($this->event);
            $envelope['_deprecation'] = [
                'message' => "This event is deprecated and will be removed in a future version.",
                'replacement' => $replacement,
                'sunset_date' => '2026-06-01',
            ];
        }

        return $envelope;
    }

    /**
     * Get HTTP headers for this webhook delivery.
     *
     * @return array<string, string>
     */
    public function headers(string $signature, int $attempt = 1): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'X-PublishLayer-Event' => $this->event,
            'X-PublishLayer-Event-Version' => $this->eventVersion,
            'X-PublishLayer-Event-ID' => $this->eventId,
            'X-PublishLayer-Signature' => 'sha256=' . $signature,
            'X-PublishLayer-Delivery-Attempt' => (string) $attempt,
            'X-PublishLayer-Timestamp' => $this->sentAt,
        ];

        if ($this->isDeprecated()) {
            $headers['X-PublishLayer-Deprecation'] = 'true';
            $headers['Sunset'] = 'Sun, 01 Jun 2026 00:00:00 GMT';
        }

        return $headers;
    }

    /**
     * Encode the envelope as JSON.
     */
    public function toJson(): string
    {
        return (string) json_encode(
            $this->toArray(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * Calculate HMAC signature for the payload.
     */
    public function sign(string $secret): string
    {
        return hash_hmac('sha256', $this->toJson(), $secret);
    }

    // =========================================================================
    // Legacy Compatibility
    // =========================================================================

    /**
     * Convert to legacy payload format for backwards compatibility.
     *
     * The legacy format uses 'id' instead of 'event_id' and lacks versioning.
     *
     * @deprecated Use toArray() instead
     * @return array<string, mixed>
     */
    public function toLegacyArray(): array
    {
        return [
            'event' => $this->event,
            'id' => $this->eventId,
            'sent_at' => $this->sentAt,
            'data' => $this->data,
        ];
    }

    /**
     * Get legacy HTTP headers.
     *
     * @deprecated Use headers() instead
     * @return array<string, string>
     */
    public function legacyHeaders(string $signature, int $attempt = 1): array
    {
        return [
            'Content-Type' => 'application/json',
            'X-PublishLayer-Event' => $this->event,
            'X-PublishLayer-Signature' => 'sha256=' . $signature,
            'X-PublishLayer-Delivery-Attempt' => (string) $attempt,
        ];
    }
}
