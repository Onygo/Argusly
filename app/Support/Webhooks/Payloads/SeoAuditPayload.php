<?php

namespace App\Support\Webhooks\Payloads;

use App\Models\SeoAudit;
use App\Support\Webhooks\AbstractWebhookPayload;
use App\Support\Webhooks\WebhookEventRegistry;

/**
 * Payload builder for SEO audit events.
 *
 * Used for:
 * - seo_audit.completed
 * - seo_audit.failed
 */
class SeoAuditPayload extends AbstractWebhookPayload
{
    public function __construct(
        private SeoAudit $audit,
        private string $eventType,
        private ?string $operationId = null,
        private ?string $error = null,
    ) {}

    public static function completed(SeoAudit $audit, ?string $operationId = null): self
    {
        return new self(
            audit: $audit,
            eventType: WebhookEventRegistry::SEO_AUDIT_COMPLETED,
            operationId: $operationId,
        );
    }

    public static function failed(SeoAudit $audit, string $error, ?string $operationId = null): self
    {
        return new self(
            audit: $audit,
            eventType: WebhookEventRegistry::SEO_AUDIT_FAILED,
            operationId: $operationId,
            error: $error,
        );
    }

    public function eventType(): string
    {
        return $this->eventType;
    }

    public function toArray(): array
    {
        $payload = [
            'seo_audit_id' => $this->audit->id,
            'status' => $this->audit->status,
            'workspace_id' => $this->audit->workspace_id,
            'client_site_id' => $this->audit->client_site_id,
        ];

        if ($this->operationId !== null) {
            $payload['operation_id'] = $this->operationId;
        }

        if ($this->eventType === WebhookEventRegistry::SEO_AUDIT_COMPLETED) {
            $payload['pages_crawled'] = $this->audit->pages_crawled ?? 0;
            $payload['issues_found'] = $this->audit->issues_found ?? 0;
            $payload['score'] = $this->audit->score;
            $payload['completed_at'] = $this->formatDateTime($this->audit->completed_at ?? now());
        }

        if ($this->error !== null) {
            $payload['error'] = $this->error;
        }

        return $payload;
    }

    public function links(): array
    {
        return [
            'audit' => $this->buildApiLink("headless/seo-audits/{$this->audit->id}"),
        ];
    }

    public function meta(): array
    {
        return [
            'client_site_id' => $this->audit->client_site_id,
        ];
    }
}
