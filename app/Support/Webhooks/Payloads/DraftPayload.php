<?php

namespace App\Support\Webhooks\Payloads;

use App\Models\Draft;
use App\Support\Webhooks\AbstractWebhookPayload;
use App\Support\Webhooks\WebhookEventRegistry;

/**
 * Payload builder for draft generation events.
 *
 * Used for:
 * - draft.generation.started
 * - draft.generation.succeeded
 * - draft.generation.failed
 * - draft.translation.succeeded
 *
 * Also supports legacy event compatibility:
 * - draft.generation.completed (deprecated)
 * - draft.translated (deprecated)
 */
class DraftPayload extends AbstractWebhookPayload
{
    public function __construct(
        private Draft $draft,
        private string $eventType,
        private ?string $operationId = null,
        private ?string $error = null,
        private bool $isRegeneration = false,
        private ?string $sourceLanguage = null,
        private ?string $targetLanguage = null,
    ) {}

    public static function generationStarted(
        Draft $draft,
        ?string $operationId = null,
        bool $isRegeneration = false,
    ): self {
        return new self(
            draft: $draft,
            eventType: WebhookEventRegistry::DRAFT_GENERATION_STARTED,
            operationId: $operationId,
            isRegeneration: $isRegeneration,
        );
    }

    public static function generationSucceeded(Draft $draft, ?string $operationId = null): self
    {
        return new self(
            draft: $draft,
            eventType: WebhookEventRegistry::DRAFT_GENERATION_SUCCEEDED,
            operationId: $operationId,
        );
    }

    public static function generationFailed(Draft $draft, string $error, ?string $operationId = null): self
    {
        return new self(
            draft: $draft,
            eventType: WebhookEventRegistry::DRAFT_GENERATION_FAILED,
            operationId: $operationId,
            error: $error,
        );
    }

    public static function translationSucceeded(
        Draft $translatedDraft,
        Draft $sourceDraft,
        ?string $operationId = null,
    ): self {
        return new self(
            draft: $translatedDraft,
            eventType: WebhookEventRegistry::DRAFT_TRANSLATION_SUCCEEDED,
            operationId: $operationId,
            sourceLanguage: $sourceDraft->language?->value,
            targetLanguage: $translatedDraft->language?->value,
        );
    }

    // =========================================================================
    // Legacy Factory Methods (for backwards compatibility)
    // =========================================================================

    /**
     * @deprecated Use generationSucceeded() instead
     */
    public static function legacyGenerationCompleted(Draft $draft, ?string $operationId = null): self
    {
        return new self(
            draft: $draft,
            eventType: WebhookEventRegistry::LEGACY_DRAFT_GENERATION_COMPLETED,
            operationId: $operationId,
        );
    }

    /**
     * @deprecated Use translationSucceeded() instead
     */
    public static function legacyTranslated(
        Draft $translatedDraft,
        Draft $sourceDraft,
        ?string $operationId = null,
    ): self {
        return new self(
            draft: $translatedDraft,
            eventType: WebhookEventRegistry::LEGACY_DRAFT_TRANSLATED,
            operationId: $operationId,
            sourceLanguage: $sourceDraft->language?->value,
            targetLanguage: $translatedDraft->language?->value,
        );
    }

    public function eventType(): string
    {
        return $this->eventType;
    }

    public function toArray(): array
    {
        $payload = [
            'draft_id' => $this->draft->id,
            'brief_id' => $this->draft->brief_id,
            'article_id' => $this->draft->content_id,
            'title' => $this->draft->title,
            'status' => $this->draft->status,
            'language' => $this->draft->language?->value,
            'model_used' => $this->draft->model_used,
            'workspace_id' => $this->draft->clientSite?->workspace_id,
            'client_site_id' => $this->draft->client_site_id,
            'destination_id' => $this->draft->content_destination_id,
        ];

        if ($this->operationId !== null) {
            $payload['operation_id'] = $this->operationId;
        }

        if ($this->error !== null) {
            $payload['error'] = $this->error;
        }

        if ($this->isRegeneration) {
            $payload['is_regeneration'] = true;
        }

        // Add translation-specific fields
        if ($this->isTranslationEvent()) {
            $payload['source_draft_id'] = $this->draft->source_draft_id;
            $payload['source_language'] = $this->sourceLanguage;
            $payload['target_language'] = $this->targetLanguage;
        }

        // Add timing for succeeded events
        if ($this->isSuccessEvent()) {
            $payload['completed_at'] = $this->formatDateTime(now());
        }

        return $payload;
    }

    public function links(): array
    {
        $links = [
            'draft' => $this->buildApiLink("drafts/{$this->draft->id}"),
        ];

        if ($this->draft->brief_id) {
            $links['brief'] = $this->buildApiLink("briefs/{$this->draft->brief_id}");
        }

        if ($this->draft->content_id) {
            $links['article'] = $this->buildApiLink("articles/{$this->draft->content_id}");
        }

        return $links;
    }

    public function meta(): array
    {
        return [
            'content_destination_id' => $this->draft->content_destination_id,
        ];
    }

    private function isTranslationEvent(): bool
    {
        return in_array($this->eventType, [
            WebhookEventRegistry::DRAFT_TRANSLATION_SUCCEEDED,
            WebhookEventRegistry::LEGACY_DRAFT_TRANSLATED,
        ], true);
    }

    private function isSuccessEvent(): bool
    {
        return in_array($this->eventType, [
            WebhookEventRegistry::DRAFT_GENERATION_SUCCEEDED,
            WebhookEventRegistry::DRAFT_TRANSLATION_SUCCEEDED,
            WebhookEventRegistry::LEGACY_DRAFT_GENERATION_COMPLETED,
            WebhookEventRegistry::LEGACY_DRAFT_TRANSLATED,
        ], true);
    }
}
