<?php

namespace App\Http\Controllers\Api\V1\Headless;

use App\Http\Controllers\Api\V1\Concerns\RespondsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Headless\CreateApiKeyRequest;
use App\Http\Resources\Api\V1\ApiKeyResource;
use App\Models\ApiKey;
use App\Services\Integrations\ApiCapabilityService;
use App\Services\Integrations\ApiKeyService;
use Illuminate\Http\Request;

class ApiKeyController extends Controller
{
    use RespondsWithApi;

    public function index(Request $request)
    {
        $workspace = $request->attributes->get('workspace');

        $keys = ApiKey::query()
            ->where('workspace_id', $workspace->id)
            ->where(function ($query): void {
                $query->whereNull('is_legacy_import')
                    ->orWhere('is_legacy_import', false);
            })
            ->orderByDesc('created_at')
            ->get();

        return $this->success(ApiKeyResource::collection($keys)->resolve());
    }

    public function store(
        CreateApiKeyRequest $request,
        ApiKeyService $apiKeyService,
        ApiCapabilityService $capabilities
    ) {
        $workspace = $request->attributes->get('workspace');

        try {
            $capabilities->assertApiOnlyEnabled($workspace);
            $capabilities->assertCanCreateApiKey($workspace);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), code: 'PLAN_LIMIT_REACHED', status: 422);
        }

        $validated = $request->validated();

        if (! empty($validated['content_destination_id'])) {
            $belongs = $workspace->contentDestinations()
                ->where('id', $validated['content_destination_id'])
                ->exists();
            if (! $belongs) {
                return $this->error(
                    'Destination not found for workspace',
                    ['content_destination_id' => ['Invalid destination id']],
                    'DESTINATION_NOT_FOUND',
                    422
                );
            }
        }

        $created = $apiKeyService->create(
            workspace: $workspace,
            name: (string) $validated['name'],
            scopes: array_values($validated['scopes']),
            contentDestinationId: $validated['content_destination_id'] ?? null,
            createdBy: optional($request->user())->id,
            expiresAt: isset($validated['expires_at']) ? new \DateTimeImmutable($validated['expires_at']) : null,
        );

        /** @var ApiKey $model */
        $model = $created['model'];

        return $this->success(
            data: (new ApiKeyResource($model))->resolve(),
            meta: [
                'plain_text_key' => $created['plain_text_key'],
                'message' => 'Store this key now. It will only be shown once.',
            ],
            status: 201,
        );
    }

    public function destroy(Request $request, string $apiKey)
    {
        $workspace = $request->attributes->get('workspace');

        $key = ApiKey::query()
            ->where('workspace_id', $workspace->id)
            ->where(function ($query): void {
                $query->whereNull('is_legacy_import')
                    ->orWhere('is_legacy_import', false);
            })
            ->where('id', $apiKey)
            ->firstOrFail();

        $key->delete();

        return $this->success(['deleted' => true]);
    }

    public function revoke(Request $request, string $apiKey, ApiKeyService $apiKeyService)
    {
        $workspace = $request->attributes->get('workspace');

        $key = ApiKey::query()
            ->where('workspace_id', $workspace->id)
            ->where(function ($query): void {
                $query->whereNull('is_legacy_import')
                    ->orWhere('is_legacy_import', false);
            })
            ->where('id', $apiKey)
            ->firstOrFail();

        $apiKeyService->revoke($key);

        return $this->success((new ApiKeyResource($key->fresh()))->resolve());
    }
}
