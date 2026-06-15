<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\RespondsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\RecommendedActionResource;
use App\Models\RecommendedAction;
use App\Services\Api\ApiScopes;
use App\Services\RecommendedActions\RecommendedActionEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecommendedActionController extends Controller
{
    use RespondsWithApi;

    public function index(Request $request, RecommendedActionEngine $engine): JsonResponse
    {
        [, $workspace, $forbidden] = $this->authorizeRead($request);
        if ($forbidden) {
            return $forbidden;
        }

        $engine->hydrateWorkspace($workspace, 6);

        $actions = RecommendedAction::query()
            ->forWorkspace($workspace)
            ->visible()
            ->when($request->query('source_group'), fn ($query, $sourceGroup) => $query->where('source_group', $sourceGroup))
            ->when($request->query('priority'), fn ($query, $priority) => $query->where('priority_label', $priority))
            ->orderByDesc('priority_score')
            ->latest()
            ->paginate((int) min(max((int) $request->integer('per_page', 25), 1), 100));

        return $this->success(
            RecommendedActionResource::collection($actions->getCollection())->resolve(),
            meta: [
                'current_page' => $actions->currentPage(),
                'last_page' => $actions->lastPage(),
                'per_page' => $actions->perPage(),
                'total' => $actions->total(),
            ],
            links: [
                'self' => $actions->url($actions->currentPage()),
                'next' => $actions->nextPageUrl(),
                'prev' => $actions->previousPageUrl(),
            ],
        );
    }

    public function show(Request $request, string $action, RecommendedActionEngine $engine): JsonResponse
    {
        [, $workspace, $forbidden] = $this->authorizeRead($request);
        if ($forbidden) {
            return $forbidden;
        }

        $engine->hydrateWorkspace($workspace, 6);

        $model = RecommendedAction::query()
            ->forWorkspace($workspace)
            ->findOrFail($action);

        return $this->success((new RecommendedActionResource($model))->resolve());
    }

    private function authorizeRead(Request $request): array
    {
        $apiKey = $request->attributes->get('apiKey');
        $workspace = $request->attributes->get('workspace');

        if (! $apiKey || ! $workspace) {
            return [null, null, response()->json(['error' => 'Forbidden'], 403)];
        }

        if (! $apiKey->hasScope(ApiScopes::CONTENT_READ)) {
            return [null, null, $this->error('Forbidden', code: 'AUTH_FORBIDDEN', status: 403)];
        }

        return [$apiKey, $workspace, null];
    }
}
