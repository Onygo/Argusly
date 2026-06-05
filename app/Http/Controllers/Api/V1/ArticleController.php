<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\RespondsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ArticleDraftResource;
use App\Http\Resources\Api\V1\ArticleResource;
use App\Http\Resources\Api\V1\PublicationResource;
use App\Services\Api\ApiScopes;
use App\Services\Api\ArticlePublishService;
use App\Services\Api\ArticleReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ArticleController extends Controller
{
    use RespondsWithApi;

    public function __construct(
        private readonly ArticleReadService $articles,
        private readonly ArticlePublishService $publisher,
    ) {}

    public function index(Request $request): JsonResponse
    {
        [$apiKey, $workspace, $forbidden] = $this->authorizeRead($request);
        if ($forbidden) {
            return $forbidden;
        }

        $paginator = $this->articles->paginateForWorkspace($workspace, $request->all());

        return $this->success(
            ArticleResource::collection($paginator->getCollection())->resolve(),
            meta: [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            links: [
                'self' => $paginator->url($paginator->currentPage()),
                'next' => $paginator->nextPageUrl(),
                'prev' => $paginator->previousPageUrl(),
            ],
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        [, $workspace, $forbidden] = $this->authorizeRead($request);
        if ($forbidden) {
            return $forbidden;
        }

        $article = $this->articles->findForWorkspace($workspace, $id);

        return $this->success((new ArticleResource($article))->resolve());
    }

    public function drafts(Request $request, string $id): JsonResponse
    {
        [, $workspace, $forbidden] = $this->authorizeRead($request);
        if ($forbidden) {
            return $forbidden;
        }

        $drafts = $this->articles->draftsForArticle($workspace, $id);

        return $this->success(ArticleDraftResource::collection($drafts)->resolve());
    }

    public function publications(Request $request, string $id): JsonResponse
    {
        [, $workspace, $forbidden] = $this->authorizeRead($request);
        if ($forbidden) {
            return $forbidden;
        }

        $publications = $this->articles->publicationsForArticle($workspace, $id);

        return $this->success(PublicationResource::collection($publications)->resolve());
    }

    public function publication(Request $request, string $id, string $publicationId): JsonResponse
    {
        [, $workspace, $forbidden] = $this->authorizeRead($request);
        if ($forbidden) {
            return $forbidden;
        }

        $publication = $this->articles->publicationForArticle($workspace, $id, $publicationId);

        return $this->success((new PublicationResource($publication))->resolve());
    }

    public function publish(Request $request, string $id): JsonResponse
    {
        [, $workspace, $forbidden] = $this->authorizePublish($request);
        if ($forbidden) {
            return $forbidden;
        }

        $article = $this->articles->findForWorkspace($workspace, $id);
        $force = (bool) $request->boolean('force', false);

        $result = $this->publisher->dispatchPublish($workspace, $article, options: ['force' => $force]);

        if (! $result['queued']) {
            return $this->error($result['message'], code: 'PUBLISH_NOT_SUPPORTED', status: 422);
        }

        return $this->success([
            'publication_id' => $result['publication_id'],
            'message' => $result['message'],
        ], status: 202);
    }

    public function publishStatus(Request $request, string $id): JsonResponse
    {
        [, $workspace, $forbidden] = $this->authorizeRead($request);
        if ($forbidden) {
            return $forbidden;
        }

        $article = $this->articles->findForWorkspace($workspace, $id);
        $status = $this->publisher->getPublishStatus($workspace, $article);

        return $this->success($status);
    }

    public function verifyPublication(Request $request, string $id, string $publicationId): JsonResponse
    {
        [, $workspace, $forbidden] = $this->authorizePublish($request);
        if ($forbidden) {
            return $forbidden;
        }

        $article = $this->articles->findForWorkspace($workspace, $id);
        $publication = $this->articles->publicationForArticle($workspace, $id, $publicationId);

        $result = $this->publisher->verifyPublication($workspace, $article, $publication);

        return $this->success($result);
    }

    private function authorizeRead(Request $request): array
    {
        $apiKey = $request->attributes->get('apiKey');
        $workspace = $request->attributes->get('workspace');

        if (! $apiKey || ! $workspace) {
            return [null, null, response()->json(['error' => 'Forbidden'], 403)];
        }

        if (! $apiKey->hasScope(ApiScopes::CONTENT_READ) && ! $apiKey->hasScope(ApiScopes::DRAFTS_READ)) {
            return [null, null, $this->error('Forbidden', code: 'AUTH_FORBIDDEN', status: 403)];
        }

        return [$apiKey, $workspace, null];
    }

    private function authorizePublish(Request $request): array
    {
        $apiKey = $request->attributes->get('apiKey');
        $workspace = $request->attributes->get('workspace');

        if (! $apiKey || ! $workspace) {
            return [null, null, response()->json(['error' => 'Forbidden'], 403)];
        }

        if (! $apiKey->hasScope(ApiScopes::CONTENT_PUBLISH)) {
            return [null, null, $this->error('Forbidden - requires content:publish scope', code: 'AUTH_FORBIDDEN', status: 403)];
        }

        return [$apiKey, $workspace, null];
    }
}
