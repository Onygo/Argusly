<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\RespondsWithApi;
use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Services\Api\ApiScopes;
use App\Services\Content\ContentDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentDeletionController extends Controller
{
    use RespondsWithApi;

    public function __construct(
        private readonly ContentDeletionService $deletions,
    ) {}

    public function destroy(Request $request, string $id): JsonResponse
    {
        [, $workspace, $forbidden] = $this->authorizeWrite($request);
        if ($forbidden) {
            return $forbidden;
        }

        $content = Content::query()
            ->whereHas('clientSite', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->whereKey($id)
            ->firstOrFail();

        $result = $this->deletions->deleteContent(
            $content,
            (string) $request->query('scope', 'single')
        );

        return $this->success($result);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        [, $workspace, $forbidden] = $this->authorizeWrite($request);
        if ($forbidden) {
            return $forbidden;
        }

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'string'],
            'scope' => ['nullable', 'in:single,family'],
        ]);

        $allowedIds = Content::query()
            ->whereHas('clientSite', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->whereIn('id', (array) $data['ids'])
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        $result = $this->deletions->bulkDelete(
            $allowedIds,
            (string) ($data['scope'] ?? 'single'),
        );

        return $this->success($result);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        [, $workspace, $forbidden] = $this->authorizeWrite($request);
        if ($forbidden) {
            return $forbidden;
        }

        $content = Content::withTrashed()
            ->whereHas('clientSite', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->whereKey($id)
            ->firstOrFail();

        $result = $this->deletions->restoreContent($content);

        return $this->success($result);
    }

    private function authorizeWrite(Request $request): array
    {
        $apiKey = $request->attributes->get('apiKey');
        $workspace = $request->attributes->get('workspace');

        if (! $apiKey || ! $workspace) {
            return [null, null, response()->json(['error' => 'Forbidden'], 403)];
        }

        if (! $apiKey->hasScope(ApiScopes::CONTENT_WRITE)) {
            return [null, null, $this->error('Forbidden - requires content:write scope', code: 'AUTH_FORBIDDEN', status: 403)];
        }

        return [$apiKey, $workspace, null];
    }
}
