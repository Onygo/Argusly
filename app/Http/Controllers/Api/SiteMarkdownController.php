<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\MarkdownArtifactResource;
use App\Http\Resources\Api\MarkdownIndexItemResource;
use App\Http\Resources\Api\StructuredAnswerBlockResource;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentRenderArtifact;
use App\Services\Api\ApiScopes;
use App\Services\Markdown\MarkdownEligibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SiteMarkdownController extends Controller
{
    public function markdown(Request $request, string $site, string $content): JsonResponse
    {
        $site = $this->resolveSite($site);
        [$contentModel, $artifact] = $this->resolveAccessibleArtifact($request, $site, $content);

        return $this->artifactResponse($request, new MarkdownArtifactResource($artifact), $contentModel, $artifact);
    }

    public function html(Request $request, string $site, string $content): JsonResponse
    {
        $site = $this->resolveSite($site);
        [$contentModel, $artifact] = $this->resolveAccessibleArtifact($request, $site, $content);

        return $this->artifactResponse($request, new MarkdownArtifactResource($artifact), $contentModel, $artifact);
    }

    public function answers(Request $request, string $site, string $content): JsonResponse
    {
        $site = $this->resolveSite($site);
        [$contentModel] = $this->resolveAccessibleArtifact($request, $site, $content);
        $contentModel->loadMissing('answerBlocks');

        return response()->json([
            'content_id' => (string) $contentModel->id,
            'slug' => (string) ($contentModel->publish_url_key ?: $contentModel->canonical_url_key ?: $contentModel->external_key ?: ''),
            'answers' => StructuredAnswerBlockResource::collection($contentModel->answerBlocks)->resolve(),
            'aeo_score' => $contentModel->aeo_score,
        ]);
    }

    public function index(Request $request, string $site): JsonResponse
    {
        $site = $this->resolveSite($site);
        $this->authorizeSiteAccess($request, $site);
        $locale = $this->resolveRequestedLocale($request);
        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));
        $page = max(1, (int) $request->query('page', 1));

        $query = Content::query()
            ->with(['workspace', 'seo', 'publications', 'renderArtifacts'])
            ->where('client_site_id', (string) $site->id)
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $items = collect($paginator->items())
            ->map(function (Content $content) use ($site, $locale) {
                $artifact = $this->resolveDeliverableArtifact($content, $locale);
                if (! $artifact) {
                    return null;
                }

                $artifact->setRelation('content', $content);

                return [
                    'content' => $content,
                    'artifact' => $artifact,
                    'site' => $site,
                    'locale' => $artifact->markdown_locale?->value ?? $locale ?? $content->language?->value ?? 'en',
                ];
            })
            ->filter()
            ->values();

        $resourceItems = MarkdownIndexItemResource::collection($items)->resolve();
        $lastModified = $this->resolveIndexLastModified($items);
        $etag = $this->resolveIndexEtag($items, $site, $locale, $page, $perPage);

        $response = response()->json([
            'items' => $resourceItems,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'has_more_pages' => $paginator->hasMorePages(),
                'locale' => $locale,
            ],
        ]);

        return $this->withCacheHeaders($request, $response, $etag, $lastModified);
    }

    /**
     * @return array{0:Content,1:ContentRenderArtifact}
     */
    private function resolveAccessibleArtifact(Request $request, ClientSite $site, string $identifier): array
    {
        $this->authorizeSiteAccess($request, $site);
        $locale = $this->resolveRequestedLocale($request);

        $content = Content::query()
            ->with(['workspace', 'seo', 'publications', 'renderArtifacts'])
            ->where('client_site_id', (string) $site->id)
            ->where(function ($query) use ($identifier): void {
                $query->where('id', $identifier)
                    ->orWhere('external_key', $identifier)
                    ->orWhere('publish_url_key', $identifier)
                    ->orWhere('canonical_url_key', $identifier);
            })
            ->firstOrFail();

        $artifact = $this->resolveDeliverableArtifact($content, $locale);
        abort_if(! $artifact, 404);
        $artifact->setRelation('content', $content);

        return [$content, $artifact];
    }

    private function authorizeSiteAccess(Request $request, ClientSite $site): void
    {
        $apiKey = $request->attributes->get('apiKey');
        $workspace = $request->attributes->get('workspace');

        if ($apiKey && $workspace) {
            abort_unless(
                $apiKey->hasScope(ApiScopes::CONTENT_READ) || $apiKey->hasScope(ApiScopes::DRAFTS_READ),
                403
            );

            abort_unless((string) $site->workspace_id === (string) $workspace->id, 404);

            return;
        }

        $siteToken = $request->attributes->get('siteToken');
        $clientSite = $request->attributes->get('clientSite');
        $resolvedSiteId = (string) ($clientSite?->id ?: $siteToken?->client_site_id ?: '');

        abort_unless($siteToken, 401);
        abort_unless($siteToken->hasScope(ApiScopes::CONTENT_READ) || $siteToken->hasScope(ApiScopes::DRAFTS_READ), 403);
        abort_unless(
            ($resolvedSiteId !== '' && $resolvedSiteId === (string) $site->id)
                || $this->requestHostMatchesSite($request, $site),
            404
        );
    }

    private function resolveRequestedLocale(Request $request): ?string
    {
        $locale = trim((string) $request->query('locale', ''));
        if ($locale === '') {
            return null;
        }

        $resolved = \App\Enums\SupportedLanguage::tryFromString($locale);
        abort_unless($resolved !== null, 422);

        return $resolved->value;
    }

    private function resolveSite(string $siteId): ClientSite
    {
        return ClientSite::query()->findOrFail($siteId);
    }

    private function requestHostMatchesSite(Request $request, ClientSite $site): bool
    {
        $claimed = trim((string) $request->header('X-PublishLayer-Site', ''));
        if ($claimed === '') {
            return false;
        }

        $host = parse_url(str_contains($claimed, '://') ? $claimed : 'https://'.$claimed, PHP_URL_HOST);
        $host = strtolower(trim((string) $host));
        if ($host === '') {
            return false;
        }

        $siteHost = strtolower(trim((string) parse_url((string) ($site->base_url ?: $site->site_url), PHP_URL_HOST)));
        if ($siteHost !== '' && $siteHost === $host) {
            return true;
        }

        $allowed = is_array($site->allowed_domains) ? $site->allowed_domains : [];

        return collect($allowed)
            ->map(fn ($domain) => strtolower(trim((string) $domain)))
            ->filter()
            ->contains($host);
    }

    private function resolveDeliverableArtifact(Content $content, ?string $requestedLocale = null): ?ContentRenderArtifact
    {
        $content->loadMissing(['workspace', 'seo', 'publications', 'renderArtifacts']);

        $decision = app(MarkdownEligibilityService::class)->evaluate($content, $requestedLocale);
        if (! $decision['eligible']) {
            return null;
        }

        $artifact = $content->renderArtifacts
            ->first(function (ContentRenderArtifact $candidate) use ($decision): bool {
                return $candidate->markdown_status === ContentRenderArtifact::STATUS_READY
                    && $candidate->markdown_locale?->value === $decision['locale'];
            });

        return $artifact instanceof ContentRenderArtifact ? $artifact : null;
    }

    private function artifactResponse(
        Request $request,
        MarkdownArtifactResource $resource,
        Content $content,
        ContentRenderArtifact $artifact
    ): JsonResponse {
        $response = response()->json($resource->resolve());

        $lastModified = $artifact->markdown_generated_at
            ?: ($content->updated_at instanceof Carbon ? $content->updated_at : null)
            ?: now();

        return $this->withCacheHeaders($request, $response, (string) $artifact->markdown_checksum, $lastModified);
    }

    private function withCacheHeaders(Request $request, JsonResponse $response, string $etag, ?Carbon $lastModified): JsonResponse
    {
        $response->setPrivate();
        $response->setEtag($etag);

        if ($lastModified) {
            $response->setLastModified($lastModified);
        }

        $response->headers->set('Vary', 'Authorization, X-PublishLayer-Site');

        if ($response->isNotModified($request)) {
            return $response;
        }

        return $response;
    }

    private function resolveIndexLastModified(Collection $items): ?Carbon
    {
        return $items
            ->map(fn (array $item) => $item['artifact']->markdown_generated_at ?: $item['content']->updated_at)
            ->filter(fn ($value) => $value instanceof Carbon)
            ->sortDesc()
            ->first();
    }

    private function resolveIndexEtag(Collection $items, ClientSite $site, ?string $locale, int $page, int $perPage): string
    {
        $payload = $items->map(fn (array $item) => [
            'content_id' => (string) $item['content']->id,
            'checksum' => (string) ($item['artifact']->markdown_checksum ?? ''),
            'updated_at' => optional($item['content']->updated_at)->toIso8601String(),
            'locale' => (string) ($item['artifact']->markdown_locale?->value ?? ''),
        ])->values()->all();

        return sha1(json_encode([
            'site' => (string) $site->id,
            'locale' => $locale,
            'page' => $page,
            'per_page' => $perPage,
            'items' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }
}
