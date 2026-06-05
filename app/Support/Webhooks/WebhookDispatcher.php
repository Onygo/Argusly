<?php

namespace App\Support\Webhooks;

use App\Models\Brief;
use App\Models\Content;
use App\Models\ContentImage;
use App\Models\ContentPublication;
use App\Models\Draft;
use App\Models\SeoAudit;
use App\Models\Workspace;
use App\Services\Integrations\ApiWebhookPublisher;
use App\Support\Webhooks\Payloads\ArticlePayload;
use App\Support\Webhooks\Payloads\DraftPayload;
use App\Support\Webhooks\Payloads\LegacyBriefPayload;
use App\Support\Webhooks\Payloads\MediaPayload;
use App\Support\Webhooks\Payloads\PublicationPayload;
use App\Support\Webhooks\Payloads\SeoAuditPayload;
use App\Support\Webhooks\Payloads\SystemPayload;

/**
 * Centralized dispatcher for all webhook events.
 *
 * This service provides typed methods for dispatching webhook events,
 * ensuring consistent payload structure and event naming.
 *
 * ## Usage
 *
 * ```php
 * app(WebhookDispatcher::class)->articleCreated($content);
 * app(WebhookDispatcher::class)->draftGenerationSucceeded($draft, $operationId);
 * app(WebhookDispatcher::class)->publicationSucceeded($content, $publication, $draft);
 * ```
 *
 * ## Migration from Legacy Events
 *
 * When using new event methods (e.g., `draftGenerationSucceeded`), the dispatcher
 * automatically also fires the legacy event (e.g., `draft.generation.completed`)
 * for backwards compatibility. This ensures existing webhook consumers continue
 * to work during the migration period.
 */
class WebhookDispatcher
{
    public function __construct(
        private ApiWebhookPublisher $publisher,
    ) {}

    // =========================================================================
    // Article Events
    // =========================================================================

    public function articleCreated(Content $article, ?string $actorUserId = null): void
    {
        $workspace = $this->resolveWorkspace($article);
        if (! $workspace) {
            return;
        }

        $this->publisher->publishPayload(
            workspace: $workspace,
            payload: ArticlePayload::created($article, $actorUserId),
        );
    }

    public function articleUpdated(Content $article, ?string $actorUserId = null): void
    {
        $workspace = $this->resolveWorkspace($article);
        if (! $workspace) {
            return;
        }

        $this->publisher->publishPayload(
            workspace: $workspace,
            payload: ArticlePayload::updated($article, $actorUserId),
        );
    }

    public function articleSubmitted(Content $article, ?string $actorUserId = null): void
    {
        $workspace = $this->resolveWorkspace($article);
        if (! $workspace) {
            return;
        }

        $this->publisher->publishPayload(
            workspace: $workspace,
            payload: ArticlePayload::submitted($article, $actorUserId),
        );
    }

    public function articleApproved(Content $article, ?string $actorUserId = null): void
    {
        $workspace = $this->resolveWorkspace($article);
        if (! $workspace) {
            return;
        }

        $this->publisher->publishPayload(
            workspace: $workspace,
            payload: ArticlePayload::approved($article, $actorUserId),
        );
    }

    public function articleRejected(Content $article, ?string $reason = null, ?string $actorUserId = null): void
    {
        $workspace = $this->resolveWorkspace($article);
        if (! $workspace) {
            return;
        }

        $this->publisher->publishPayload(
            workspace: $workspace,
            payload: ArticlePayload::rejected($article, $reason, $actorUserId),
        );
    }

    public function articleScheduled(Content $article, ?string $actorUserId = null): void
    {
        $workspace = $this->resolveWorkspace($article);
        if (! $workspace) {
            return;
        }

        $this->publisher->publishPayload(
            workspace: $workspace,
            payload: ArticlePayload::scheduled($article, $actorUserId),
        );
    }

    public function articleArchived(Content $article, ?string $actorUserId = null): void
    {
        $workspace = $this->resolveWorkspace($article);
        if (! $workspace) {
            return;
        }

        $this->publisher->publishPayload(
            workspace: $workspace,
            payload: ArticlePayload::archived($article, $actorUserId),
        );
    }

    // =========================================================================
    // Draft Events
    // =========================================================================

    public function draftGenerationStarted(
        Draft $draft,
        ?string $operationId = null,
        bool $isRegeneration = false,
    ): void {
        $workspace = $this->resolveWorkspaceFromDraft($draft);
        if (! $workspace) {
            return;
        }

        $this->publisher->publishPayload(
            workspace: $workspace,
            payload: DraftPayload::generationStarted($draft, $operationId, $isRegeneration),
            eventId: $operationId ?? (string) $draft->id,
        );
    }

    public function draftGenerationSucceeded(Draft $draft, ?string $operationId = null): void
    {
        $workspace = $this->resolveWorkspaceFromDraft($draft);
        if (! $workspace) {
            return;
        }

        $this->publisher->publishPayload(
            workspace: $workspace,
            payload: DraftPayload::generationSucceeded($draft, $operationId),
            eventId: $operationId ?? (string) $draft->id,
        );
    }

    public function draftGenerationFailed(Draft $draft, string $error, ?string $operationId = null): void
    {
        $workspace = $this->resolveWorkspaceFromDraft($draft);
        if (! $workspace) {
            return;
        }

        $this->publisher->publishPayload(
            workspace: $workspace,
            payload: DraftPayload::generationFailed($draft, $error, $operationId),
            eventId: $operationId ?? (string) $draft->id,
        );
    }

    public function draftTranslationSucceeded(
        Draft $translatedDraft,
        Draft $sourceDraft,
        ?string $operationId = null,
    ): void {
        $workspace = $this->resolveWorkspaceFromDraft($translatedDraft);
        if (! $workspace) {
            return;
        }

        $this->publisher->publishPayload(
            workspace: $workspace,
            payload: DraftPayload::translationSucceeded($translatedDraft, $sourceDraft, $operationId),
            eventId: $operationId ?? (string) $translatedDraft->id,
        );
    }

    // =========================================================================
    // Publication Events
    // =========================================================================

    public function publicationStarted(Content $article, Draft $draft, string $provider = 'wordpress'): void
    {
        $workspace = $this->resolveWorkspace($article);
        if (! $workspace) {
            return;
        }

        $this->publisher->publishPayload(
            workspace: $workspace,
            payload: PublicationPayload::started($article, $draft, $provider),
        );
    }

    public function publicationSucceeded(
        Content $article,
        ContentPublication $publication,
        ?Draft $draft = null,
    ): void {
        $workspace = $this->resolveWorkspace($article);
        if (! $workspace) {
            return;
        }

        $this->publisher->publishPayload(
            workspace: $workspace,
            payload: PublicationPayload::succeeded($article, $publication, $draft),
        );
    }

    public function publicationFailed(
        Content $article,
        string $error,
        ?Draft $draft = null,
        string $provider = 'wordpress',
    ): void {
        $workspace = $this->resolveWorkspace($article);
        if (! $workspace) {
            return;
        }

        $this->publisher->publishPayload(
            workspace: $workspace,
            payload: PublicationPayload::failed($article, $error, $draft, $provider),
        );
    }

    public function publicationVerified(Content $article, ContentPublication $publication): void
    {
        $workspace = $this->resolveWorkspace($article);
        if (! $workspace) {
            return;
        }

        $this->publisher->publishPayload(
            workspace: $workspace,
            payload: PublicationPayload::verified($article, $publication),
        );
    }

    // =========================================================================
    // Media Events
    // =========================================================================

    public function mediaGenerated(ContentImage $image, Content $article): void
    {
        $workspace = $this->resolveWorkspace($article);
        if (! $workspace) {
            return;
        }

        $this->publisher->publishPayload(
            workspace: $workspace,
            payload: MediaPayload::generated($image, $article),
        );
    }

    // =========================================================================
    // SEO Events
    // =========================================================================

    public function seoAuditCompleted(SeoAudit $audit, ?string $operationId = null): void
    {
        $audit->loadMissing('workspace');
        $workspace = $audit->workspace;

        if (! $workspace) {
            return;
        }

        $this->publisher->publishPayload(
            workspace: $workspace,
            payload: SeoAuditPayload::completed($audit, $operationId),
            eventId: $operationId ?? (string) $audit->id,
        );
    }

    public function seoAuditFailed(SeoAudit $audit, string $error, ?string $operationId = null): void
    {
        $audit->loadMissing('workspace');
        $workspace = $audit->workspace;

        if (! $workspace) {
            return;
        }

        $this->publisher->publishPayload(
            workspace: $workspace,
            payload: SeoAuditPayload::failed($audit, $error, $operationId),
            eventId: $operationId ?? (string) $audit->id,
        );
    }

    // =========================================================================
    // System Events
    // =========================================================================

    public function creditsLow(
        Workspace $workspace,
        int $currentCredits,
        int $thresholdCredits,
        ?int $percentageRemaining = null,
    ): void {
        $this->publisher->publishPayload(
            workspace: $workspace,
            payload: SystemPayload::creditsLow($workspace, $currentCredits, $thresholdCredits, $percentageRemaining),
        );
    }

    // =========================================================================
    // Legacy Events (for backwards compatibility during migration)
    // =========================================================================

    /**
     * Dispatch the legacy brief.created event.
     *
     * @deprecated Use articleCreated() instead. This method dispatches both
     *             the legacy brief.created event AND the new article.created event.
     */
    public function legacyBriefCreated(Brief $brief): void
    {
        $brief->loadMissing('workspace', 'content');
        $workspace = $brief->workspace;

        if (! $workspace) {
            return;
        }

        // Dispatch legacy event
        $this->publisher->publishPayload(
            workspace: $workspace,
            payload: LegacyBriefPayload::created($brief),
        );

        // Also dispatch new article.created if content exists
        if ($brief->content) {
            $this->articleCreated($brief->content);
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function resolveWorkspace(Content $content): ?Workspace
    {
        $content->loadMissing('workspace');

        return $content->workspace;
    }

    private function resolveWorkspaceFromDraft(Draft $draft): ?Workspace
    {
        $draft->loadMissing('clientSite.workspace');

        return $draft->clientSite?->workspace;
    }
}
