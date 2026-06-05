<?php

namespace App\Support\Webhooks;

/**
 * Abstract base class for webhook payloads.
 *
 * Provides default implementations for common methods.
 * Concrete payload classes should extend this and implement:
 * - eventType()
 * - toArray()
 */
abstract class AbstractWebhookPayload implements WebhookPayload
{
    /**
     * @inheritDoc
     */
    public function version(): string
    {
        return WebhookEventRegistry::CURRENT_VERSION;
    }

    /**
     * @inheritDoc
     */
    public function links(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function meta(): array
    {
        return [];
    }

    /**
     * Helper to format a datetime for the payload.
     */
    protected function formatDateTime(?\DateTimeInterface $dateTime): ?string
    {
        return $dateTime?->format('Y-m-d\TH:i:s.u\Z');
    }

    /**
     * Helper to build API links.
     */
    protected function buildApiLink(string $path): string
    {
        $baseUrl = rtrim((string) config('app.url'), '/');

        return $baseUrl . '/api/v1/' . ltrim($path, '/');
    }
}
