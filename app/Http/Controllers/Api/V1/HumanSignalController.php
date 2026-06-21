<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\RespondsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\HumanSignalResource;
use App\Models\HumanSignal;
use App\Models\Workspace;
use App\Services\Api\ApiScopes;
use App\Services\HumanSignals\HumanSignalDetectionService;
use App\Services\OpportunityIntelligence\OpportunityIntelligenceEngine;
use App\Services\OpportunityIntelligence\OpportunitySignalIngestor;
use App\Services\OpportunityIntelligence\OpportunitySignalPayload;
use App\Enums\OpportunityCategory;
use App\Enums\OpportunitySignalSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class HumanSignalController extends Controller
{
    use RespondsWithApi;

    public function index(Request $request): JsonResponse
    {
        [, $workspace, $forbidden] = $this->authorizeRead($request);
        if ($forbidden) {
            return $forbidden;
        }

        $signals = HumanSignal::query()
            ->with(['evidence', 'insights'])
            ->where('workspace_id', (string) $workspace->id)
            ->when($request->query('type'), fn ($query, $type) => $query->where('type', $type))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('confidence_score')
            ->latest('detected_at')
            ->paginate((int) min(max((int) $request->integer('per_page', 25), 1), 100));

        return $this->success(
            HumanSignalResource::collection($signals->getCollection())->resolve(),
            meta: [
                'current_page' => $signals->currentPage(),
                'last_page' => $signals->lastPage(),
                'per_page' => $signals->perPage(),
                'total' => $signals->total(),
            ],
            links: [
                'self' => $signals->url($signals->currentPage()),
                'next' => $signals->nextPageUrl(),
                'prev' => $signals->previousPageUrl(),
            ],
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        [, $workspace, $forbidden] = $this->authorizeRead($request);
        if ($forbidden) {
            return $forbidden;
        }

        $signal = HumanSignal::query()
            ->with(['evidence', 'insights'])
            ->where('workspace_id', (string) $workspace->id)
            ->findOrFail($id);

        return $this->success((new HumanSignalResource($signal))->resolve());
    }

    public function detect(Request $request, HumanSignalDetectionService $detector): JsonResponse
    {
        [$apiKey, $workspace, $forbidden] = $this->authorizeRead($request);
        if ($forbidden) {
            return $forbidden;
        }

        if (! $apiKey->hasScope(ApiScopes::CONTENT_WRITE)) {
            return $this->error('Forbidden', code: 'AUTH_FORBIDDEN', status: 403);
        }

        $signals = $detector->detectForWorkspace($workspace);

        return $this->success([
            'detected_count' => $signals->count(),
            'signals' => HumanSignalResource::collection($signals->load(['evidence', 'insights']))->resolve(),
        ]);
    }

    public function generateContent(Request $request, string $id): JsonResponse
    {
        [, $workspace, $forbidden] = $this->authorizeRead($request);
        if ($forbidden) {
            return $forbidden;
        }

        $signal = HumanSignal::query()
            ->with(['evidence', 'insights'])
            ->where('workspace_id', (string) $workspace->id)
            ->findOrFail($id);

        return $this->success([
            'content_prompt_context' => [
                'source' => 'human_signal',
                'human_signal_id' => (string) $signal->id,
                'title' => $signal->title,
                'observation' => $signal->observation,
                'impact' => $signal->impact,
                'insight' => $signal->insights->first()?->insight,
                'instructions' => [
                    'Use this Human Signal as the primary source of originality.',
                    'Prefer the detected observation over generic industry advice.',
                    'Include the signal-driven insight when relevant.',
                ],
            ],
        ]);
    }

    public function createOpportunity(
        Request $request,
        string $id,
        OpportunitySignalIngestor $ingestor,
        OpportunityIntelligenceEngine $engine,
    ): JsonResponse {
        [$apiKey, $workspace, $forbidden] = $this->authorizeRead($request);
        if ($forbidden) {
            return $forbidden;
        }

        if (! $apiKey->hasScope(ApiScopes::CONTENT_WRITE)) {
            return $this->error('Forbidden', code: 'AUTH_FORBIDDEN', status: 403);
        }

        $signal = HumanSignal::query()
            ->where('workspace_id', (string) $workspace->id)
            ->findOrFail($id);

        $category = $this->opportunityCategoryForSignal($signal);
        $topic = (string) data_get($signal->metadata_json, 'topic', $signal->title);

        $ingestor->ingest($workspace, new OpportunitySignalPayload(
            source: OpportunitySignalSource::SIGNAL_INTELLIGENCE,
            category: $category,
            topic: Str::limit($topic, 220, ''),
            entity: null,
            signalStrength: (float) data_get($signal->metadata_json, 'quality.human_signal_score', $signal->confidence_score),
            confidence: (float) $signal->confidence_score,
            observedAt: $signal->detected_at,
            clientSiteId: $signal->site_id,
            metrics: [
                'human_signal_score' => data_get($signal->metadata_json, 'quality.human_signal_score'),
                'confidence_score' => $signal->confidence_score,
            ],
            evidence: [
                'human_signal_id' => (string) $signal->id,
                'title' => $signal->title,
                'observation' => $signal->observation,
                'impact' => $signal->impact,
            ],
            metadata: [
                'human_signal_id' => (string) $signal->id,
                'source' => 'human_signal',
                'signal_type' => $signal->type?->value ?? $signal->type,
            ],
        ));

        $created = $engine->run($workspace);
        $signal->forceFill(['status' => HumanSignal::STATUS_ACTIONED])->save();

        return $this->success([
            'status' => 'created',
            'human_signal_id' => (string) $signal->id,
            'opportunities_created' => $created['created'] ?? null,
            'opportunities_updated' => $created['updated'] ?? null,
        ]);
    }

    private function authorizeRead(Request $request): array
    {
        $apiKey = $request->attributes->get('apiKey');
        $workspace = $request->attributes->get('workspace');

        if (! $apiKey || ! $workspace instanceof Workspace) {
            return [null, null, response()->json(['error' => 'Forbidden'], 403)];
        }

        if (! $apiKey->hasScope(ApiScopes::CONTENT_READ)) {
            return [null, null, $this->error('Forbidden', code: 'AUTH_FORBIDDEN', status: 403)];
        }

        return [$apiKey, $workspace, null];
    }

    private function opportunityCategoryForSignal(HumanSignal $signal): OpportunityCategory
    {
        $type = (string) ($signal->type?->value ?? $signal->type);

        return match ($type) {
            'competitor_shift' => OpportunityCategory::COMPETITOR_MOVEMENT,
            'visibility_trend', 'citation_pattern', 'authority_growth', 'authority_decline' => OpportunityCategory::AI_VISIBILITY_OPPORTUNITY,
            'content_performance', 'conversion_pattern', 'campaign_pattern' => OpportunityCategory::ENGAGEMENT_OPPORTUNITY,
            'emerging_topic', 'topic_opportunity' => OpportunityCategory::TREND_OPPORTUNITY,
            default => OpportunityCategory::CONTENT_GAP,
        };
    }
}
