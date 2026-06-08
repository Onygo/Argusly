<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ContentDestinationSyncAttempt;
use App\Services\Api\ApiScopes;
use App\Support\Connectors\ConnectorHeaders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ConnectorContentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeRead($request);

        $query = $this->baseQuery($request)
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', (string) $status))
            ->when($request->query('locale'), fn ($query, $locale) => $query->where('language', (string) $locale))
            ->when($request->query('updated_since'), fn ($query, $updatedSince) => $query->where('updated_at', '>=', (string) $updatedSince))
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        $limit = max(1, min(100, (int) $request->query('limit', 25)));
        $items = $query->limit($limit)->get();

        return response()->json([
            'ok' => true,
            'data' => $items->map(fn (Content $content): array => $this->contentPayload($content))->values(),
            'meta' => [
                'limit' => $limit,
                'count' => $items->count(),
            ],
            'errors' => [],
        ]);
    }

    public function show(Request $request, string $content): JsonResponse
    {
        $this->authorizeRead($request);

        $contentModel = $this->baseQuery($request)
            ->where(function ($query) use ($content): void {
                $query->where('id', $content)
                    ->orWhere('external_key', $content)
                    ->orWhere('publish_url_key', $content)
                    ->orWhere('canonical_url_key', $content);
            })
            ->firstOrFail();

        return response()->json([
            'ok' => true,
            'data' => $this->contentPayload($contentModel, includeBody: true),
            'meta' => [],
            'errors' => [],
        ]);
    }

    public function syncResults(Request $request, string $content): JsonResponse
    {
        $this->authorizeSync($request);

        $contentModel = $this->baseQuery($request)
            ->where('id', $content)
            ->firstOrFail();

        $data = $request->validate([
            'operation' => ['nullable', 'string', 'max:64'],
            'status' => ['required', 'string', 'max:64'],
            'remote_id' => ['nullable', 'string', 'max:255'],
            'remote_url' => ['nullable', 'url', 'max:2048'],
            'errors' => ['nullable', 'array'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
            'capabilities_observed' => ['nullable', 'array'],
            'meta' => ['nullable', 'array'],
        ]);

        $destination = $this->resolveDestination($request);
        if ($destination) {
            ContentDestinationSyncAttempt::query()->create([
                'workspace_id' => $contentModel->workspace_id,
                'content_destination_id' => $destination->id,
                'content_id' => $contentModel->id,
                'sync_type' => 'connector_result',
                'trigger_source' => 'connector',
                'status' => (string) $data['status'],
                'attempt' => 1,
                'idempotency_key' => (string) ($data['idempotency_key'] ?? ConnectorHeaders::value($request, ConnectorHeaders::IDEMPOTENCY_KEY)),
                'request_headers' => [
                    ConnectorHeaders::SITE => ConnectorHeaders::site($request),
                    ConnectorHeaders::DESTINATION_ID => ConnectorHeaders::destinationId($request),
                ],
                'request_body' => $data,
                'response_status' => 202,
                'started_at' => now(),
                'delivered_at' => now(),
            ]);
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'content_id' => (string) $contentModel->id,
                'status' => (string) $data['status'],
            ],
            'meta' => [
                'accepted_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ], 202);
    }

    private function baseQuery(Request $request)
    {
        $workspace = $request->attributes->get('workspace');
        $siteToken = $request->attributes->get('siteToken');
        $clientSite = $request->attributes->get('clientSite');

        return Content::query()
            ->with(['currentVersion', 'renderArtifacts'])
            ->when($workspace, fn ($query) => $query->where('workspace_id', $workspace->id))
            ->when($siteToken || $clientSite, function ($query) use ($siteToken, $clientSite): void {
                $siteId = (string) ($clientSite?->id ?: $siteToken?->client_site_id ?: '');
                if ($siteId !== '') {
                    $query->where('client_site_id', $siteId);
                }
            });
    }

    private function authorizeRead(Request $request): void
    {
        $this->authorizeConnectorScope($request, [ApiScopes::CONTENT_READ, ApiScopes::DRAFTS_READ]);
    }

    private function authorizeSync(Request $request): void
    {
        $this->authorizeConnectorScope($request, [ApiScopes::CONTENT_WRITE, ApiScopes::CONTENT_PUBLISH, 'content:push']);
    }

    /**
     * @param array<int, string> $scopes
     */
    private function authorizeConnectorScope(Request $request, array $scopes): void
    {
        $apiKey = $request->attributes->get('apiKey');
        if ($apiKey) {
            abort_unless(collect($scopes)->contains(fn (string $scope): bool => $apiKey->hasScope($scope)), 403);

            return;
        }

        $siteToken = $request->attributes->get('siteToken');
        abort_unless($siteToken, 401);
        abort_unless(collect($scopes)->contains(fn (string $scope): bool => $siteToken->hasScope($scope)), 403);
    }

    private function resolveDestination(Request $request): ?ContentDestination
    {
        $destination = $request->attributes->get('contentDestination');
        if ($destination instanceof ContentDestination) {
            return $destination;
        }

        $workspace = $request->attributes->get('workspace');
        $destinationId = ConnectorHeaders::destinationId($request);

        if (! $workspace || $destinationId === '') {
            return null;
        }

        return $workspace->contentDestinations()->where('id', $destinationId)->first();
    }

    private function contentPayload(Content $content, bool $includeBody = false): array
    {
        $artifact = $content->renderArtifacts
            ->sortByDesc('markdown_generated_at')
            ->first();

        $payload = [
            'id' => (string) $content->id,
            'title' => (string) $content->title,
            'status' => (string) ($content->publish_status ?: $content->status),
            'locale' => (string) ($content->language?->value ?? ''),
            'slug' => (string) ($content->publish_url_key ?: $content->canonical_url_key ?: Str::slug((string) $content->title)),
            'canonical_url' => (string) ($content->seo_canonical ?: $content->published_url ?: ''),
            'updated_at' => optional($content->updated_at)->toIso8601String(),
        ];

        if ($includeBody) {
            $payload['rendered_markdown'] = (string) ($artifact?->rendered_markdown ?? '');
            $payload['rendered_html'] = (string) ($artifact?->rendered_html ?? $content->currentVersion?->body ?? '');
            $payload['markdown_checksum'] = (string) ($artifact?->markdown_checksum ?? '');
        }

        return $payload;
    }
}
