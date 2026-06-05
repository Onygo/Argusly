<?php

namespace App\Jobs\Integrations;

use App\Models\ApiWebhook;
use App\Models\ApiWebhookDelivery;
use App\Support\Webhooks\WebhookEventRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Job to deliver webhook payloads to external endpoints.
 *
 * ## Phase 2 Refactor
 *
 * This job now supports:
 * - Event versioning headers (X-PublishLayer-Event-Version)
 * - Event ID headers (X-PublishLayer-Event-ID)
 * - Timestamp headers (X-PublishLayer-Timestamp)
 * - Deprecation headers (X-PublishLayer-Deprecation, Sunset)
 *
 * The payload now uses the full envelope format when provided by
 * the publisher, falling back to legacy format for backwards compatibility.
 */
class DeliverApiWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 4;

    public int $timeout = 30;

    public function backoff(): array
    {
        return [30, 120, 300, 900];
    }

    /**
     * @param  array<string, mixed>  $payload  The full envelope or legacy payload
     */
    public function __construct(
        public string $webhookId,
        public string $eventType,
        public array $payload,
        public ?string $eventId = null,
        public ?string $eventVersion = null,
    ) {}

    public function handle(): void
    {
        $webhook = ApiWebhook::query()->find($this->webhookId);
        if (! $webhook || ! $webhook->is_active) {
            return;
        }

        // Determine if payload is already an envelope or needs wrapping
        $body = $this->resolveBody();

        $encodedBody = (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $encodedBody, (string) $webhook->secret);

        $headers = $this->buildHeaders($signature);

        $delivery = ApiWebhookDelivery::query()->create([
            'api_webhook_id' => $webhook->id,
            'workspace_id' => $webhook->workspace_id,
            'event_type' => $this->eventType,
            'event_id' => $this->eventId ?? $body['event_id'] ?? null,
            'attempt' => $this->attempts(),
            'request_headers' => $headers,
            'request_body' => $body,
        ]);

        try {
            $response = Http::timeout(15)
                ->withHeaders($headers)
                ->post($webhook->target_url, $body);

            $delivery->response_status = $response->status();
            $delivery->response_body = mb_substr((string) $response->body(), 0, 5000);

            if ($response->successful()) {
                $delivery->delivered_at = now();
                $delivery->save();

                $webhook->last_delivered_at = now();
                $webhook->save();

                return;
            }

            $delivery->failed_at = now();
            $delivery->error_message = 'Webhook target returned HTTP '.$response->status();
            if ($this->attempts() < $this->tries) {
                $backoff = $this->backoff()[$this->attempts() - 1] ?? 900;
                $delivery->next_retry_at = now()->addSeconds($backoff);
            }
            $delivery->save();

            $webhook->last_failure_at = now();
            $webhook->save();

            throw new \RuntimeException('Webhook delivery failed with HTTP '.$response->status());
        } catch (Throwable $exception) {
            $delivery->failed_at = $delivery->failed_at ?: now();
            $delivery->error_message = mb_substr($exception->getMessage(), 0, 5000);
            if ($this->attempts() < $this->tries) {
                $backoff = $this->backoff()[$this->attempts() - 1] ?? 900;
                $delivery->next_retry_at = now()->addSeconds($backoff);
            }
            $delivery->save();

            $webhook->last_failure_at = now();
            $webhook->save();

            throw $exception;
        }
    }

    /**
     * Resolve the body to send.
     *
     * If the payload already contains envelope fields (event, event_version),
     * use it directly. Otherwise, wrap in legacy format.
     *
     * @return array<string, mixed>
     */
    private function resolveBody(): array
    {
        // Check if payload is already a full envelope
        if (isset($this->payload['event']) && isset($this->payload['event_version'])) {
            return $this->payload;
        }

        // Legacy format - wrap the payload
        return [
            'event' => $this->eventType,
            'id' => $this->eventId,
            'sent_at' => now()->toIso8601String(),
            'data' => $this->payload,
        ];
    }

    /**
     * Build HTTP headers for the delivery.
     *
     * @return array<string, string>
     */
    private function buildHeaders(string $signature): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'X-PublishLayer-Event' => $this->eventType,
            'X-PublishLayer-Signature' => 'sha256='.$signature,
            'X-PublishLayer-Delivery-Attempt' => (string) $this->attempts(),
        ];

        // Add version header if available
        if ($this->eventVersion !== null) {
            $headers['X-PublishLayer-Event-Version'] = $this->eventVersion;
        }

        // Add event ID header if available
        $eventId = $this->eventId ?? $this->payload['event_id'] ?? null;
        if ($eventId !== null) {
            $headers['X-PublishLayer-Event-ID'] = $eventId;
        }

        // Add timestamp header
        $headers['X-PublishLayer-Timestamp'] = now()->format('Y-m-d\TH:i:s.u\Z');

        // Add deprecation headers for deprecated events
        if (WebhookEventRegistry::isDeprecated($this->eventType)) {
            $headers['X-PublishLayer-Deprecation'] = 'true';
            $headers['Sunset'] = 'Sun, 01 Jun 2026 00:00:00 GMT';
        }

        return $headers;
    }
}
