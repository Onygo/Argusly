<?php

namespace App\Services\Api;

use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ContentPublication;
use App\Models\Workspace;
use App\Services\Publication\ContentPublicationService;
use App\Services\Publication\WordPressPublicationDestinationResolver;
use App\Support\Connectors\ConnectorRegistry;
use App\Support\Connectors\Results\VerificationResult;
use Illuminate\Support\Facades\DB;

class ArticlePublishService
{
    public function __construct(
        private readonly ContentPublicationService $publicationService,
        private readonly WordPressPublicationDestinationResolver $destinationResolver,
        private readonly ConnectorRegistry $connectorRegistry,
    ) {}

    /**
     * Dispatch a publish job for an article.
     *
     * @param  array<string, mixed>  $options
     * @return array{queued: bool, publication_id: ?string, message: string}
     */
    public function dispatchPublish(
        Workspace $workspace,
        Content $article,
        ?ContentDestination $destination = null,
        array $options = [],
    ): array {
        $this->ensureArticleBelongsToWorkspace($workspace, $article);

        $siteType = $this->resolveSiteType($article);

        if ($siteType !== 'wordpress') {
            return [
                'queued' => false,
                'publication_id' => null,
                'message' => "Publishing for site type '{$siteType}' is not yet supported via the API.",
            ];
        }

        // Resolve or create the publication record
        $destination ??= $this->destinationResolver->resolveForContent($article);
        $publication = ContentPublication::resolveForDelivery(
            contentId: (string) $article->id,
            destinationId: $destination?->id ? (string) $destination->id : null,
            clientSiteId: $article->client_site_id,
            provider: ContentPublication::PROVIDER_WORDPRESS,
            locale: $article->language,
        );

        // Mark as pending to indicate publish is queued
        if ($publication->delivery_status === ContentPublication::STATUS_PENDING) {
            DB::transaction(function () use ($article) {
                $article->forceFill([
                    'publish_status' => 'publishing',
                    'publish_error' => null,
                ])->save();
            });
        }

        $dispatch = $this->publicationService->dispatchWordPressPublication($article, null, [
            'source' => 'api.article.publish',
        ]);

        return [
            'queued' => (bool) ($dispatch['queued'] ?? false),
            'publication_id' => (string) $publication->id,
            'message' => (bool) ($dispatch['queued'] ?? false)
                ? 'Publish job has been queued.'
                : 'Publication was already queued or processed.',
        ];
    }

    /**
     * Verify a publication's remote status.
     *
     * @return array{verified: bool, status: string, remote: array<string, mixed>, message: string}
     */
    public function verifyPublication(
        Workspace $workspace,
        Content $article,
        ContentPublication $publication,
    ): array {
        $this->ensureArticleBelongsToWorkspace($workspace, $article);
        $this->ensurePublicationBelongsToArticle($article, $publication);

        $connector = $this->connectorRegistry->resolveForPublication($publication);
        if (! $connector) {
            return [
                'verified' => false,
                'status' => 'error',
                'remote' => [],
                'message' => "No connector available for provider '{$publication->provider}'.",
            ];
        }

        $destination = $publication->destination ?? ContentDestination::query()->find($publication->destination_id);

        $result = $connector->verify($publication, $destination ?? new ContentDestination());

        $publication->forceFill([
            'last_verified_at' => now(),
        ])->save();

        return $this->formatVerificationResult($result, $publication);
    }

    /**
     * Get publish status for an article.
     *
     * @return array{status: string, publications: array<int, array<string, mixed>>}
     */
    public function getPublishStatus(Workspace $workspace, Content $article): array
    {
        $this->ensureArticleBelongsToWorkspace($workspace, $article);

        $publications = ContentPublication::query()
            ->where('content_id', $article->id)
            ->with('destination:id,name,type')
            ->latest('last_delivered_at')
            ->latest('created_at')
            ->get();

        return [
            'status' => (string) ($article->publish_status ?? 'draft'),
            'publications' => $publications->map(fn (ContentPublication $pub) => [
                'id' => (string) $pub->id,
                'provider' => (string) $pub->provider,
                'delivery_status' => (string) $pub->delivery_status,
                'remote_id' => $pub->remote_id,
                'remote_url' => $pub->remote_url,
                'last_delivered_at' => $pub->last_delivered_at?->toIso8601String(),
            ])->all(),
        ];
    }

    private function ensureArticleBelongsToWorkspace(Workspace $workspace, Content $article): void
    {
        $article->loadMissing('clientSite');

        if ((string) ($article->clientSite?->workspace_id ?? '') !== (string) $workspace->id) {
            abort(404, 'Article not found.');
        }
    }

    private function ensurePublicationBelongsToArticle(Content $article, ContentPublication $publication): void
    {
        if ((string) $publication->content_id !== (string) $article->id) {
            abort(404, 'Publication not found.');
        }
    }

    private function resolveSiteType(Content $article): string
    {
        $article->loadMissing('clientSite');

        return strtolower(trim((string) ($article->clientSite?->type ?? '')));
    }

    /**
     * @return array{verified: bool, status: string, remote: array<string, mixed>, message: string}
     */
    private function formatVerificationResult(VerificationResult $result, ContentPublication $publication): array
    {
        $remote = [
            'id' => $publication->remote_id,
            'url' => $result->remoteUrl ?? $publication->remote_url,
            'status' => $result->remoteStatus,
        ];

        if ($result->doesExist()) {
            return [
                'verified' => true,
                'status' => 'exists',
                'remote' => $remote,
                'message' => 'Remote publication verified successfully.',
            ];
        }

        if ($result->isMissing()) {
            return [
                'verified' => true,
                'status' => 'missing',
                'remote' => $remote,
                'message' => 'Remote publication was not found.',
            ];
        }

        if ($result->isTrashed()) {
            return [
                'verified' => true,
                'status' => 'trashed',
                'remote' => $remote,
                'message' => 'Remote publication has been trashed.',
            ];
        }

        return [
            'verified' => false,
            'status' => 'error',
            'remote' => $remote,
            'message' => $result->errorMessage ?? 'Verification failed.',
        ];
    }
}
