<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\RespondsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CampaignResource;
use App\Http\Resources\Api\V1\DistributionChannelResource;
use App\Models\Campaign;
use App\Models\DistributionChannel;
use App\Services\Api\ApiScopes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CampaignController extends Controller
{
    use RespondsWithApi;

    public function index(Request $request): JsonResponse
    {
        [, $workspace, $forbidden] = $this->authorizeRead($request);
        if ($forbidden) {
            return $forbidden;
        }

        $campaigns = Campaign::query()
            ->where('workspace_id', $workspace->id)
            ->withCount(['contents', 'distributionPlans'])
            ->latest()
            ->paginate((int) min(max((int) $request->integer('per_page', 25), 1), 100));

        return $this->success(
            CampaignResource::collection($campaigns->getCollection())->resolve(),
            meta: [
                'current_page' => $campaigns->currentPage(),
                'last_page' => $campaigns->lastPage(),
                'per_page' => $campaigns->perPage(),
                'total' => $campaigns->total(),
            ],
            links: [
                'self' => $campaigns->url($campaigns->currentPage()),
                'next' => $campaigns->nextPageUrl(),
                'prev' => $campaigns->previousPageUrl(),
            ],
        );
    }

    public function show(Request $request, string $campaign): JsonResponse
    {
        [, $workspace, $forbidden] = $this->authorizeRead($request);
        if ($forbidden) {
            return $forbidden;
        }

        $model = Campaign::query()
            ->where('workspace_id', $workspace->id)
            ->with(['contents.content', 'distributionPlans.distributionChannel'])
            ->withCount(['contents', 'distributionPlans'])
            ->findOrFail($campaign);

        return $this->success((new CampaignResource($model))->resolve());
    }

    public function channels(Request $request): JsonResponse
    {
        [, $workspace, $forbidden] = $this->authorizeRead($request);
        if ($forbidden) {
            return $forbidden;
        }

        $channels = DistributionChannel::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        return $this->success(DistributionChannelResource::collection($channels)->resolve());
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
