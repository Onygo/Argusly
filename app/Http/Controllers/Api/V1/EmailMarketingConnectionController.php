<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ContentDestinationStatus;
use App\Enums\EmailMarketingProvider;
use App\Http\Controllers\Api\V1\Concerns\RespondsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\EmailMarketingConnectionResource;
use App\Models\EmailMarketingConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmailMarketingConnectionController extends Controller
{
    use RespondsWithApi;

    public function index(Request $request): JsonResponse
    {
        $workspace = $request->attributes->get('workspace');
        abort_unless($workspace, 403);

        $connections = EmailMarketingConnection::query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('created_at')
            ->get();

        return $this->success(EmailMarketingConnectionResource::collection($connections)->resolve());
    }

    public function store(Request $request): JsonResponse
    {
        $workspace = $request->attributes->get('workspace');
        abort_unless($workspace, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'provider' => ['required', Rule::in(EmailMarketingProvider::values())],
            'status' => ['nullable', Rule::in(['active', 'disabled'])],
            'config' => ['nullable', 'array'],
            'config.base_url' => ['nullable', 'url', 'max:2048'],
            'config.draft_endpoint' => ['nullable', 'string', 'max:255'],
            'config.default_template_id' => ['nullable', 'string', 'max:255'],
            'config.default_audience_id' => ['nullable', 'string', 'max:255'],
            'config.timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:60'],
            'credentials' => ['nullable', 'array'],
            'credentials.api_key' => ['nullable', 'string', 'max:2000'],
        ]);

        $connection = new EmailMarketingConnection([
            'workspace_id' => $workspace->id,
            'name' => $validated['name'],
            'provider' => $validated['provider'],
            'status' => $validated['status'] ?? ContentDestinationStatus::ACTIVE->value,
            'config' => $validated['config'] ?? [],
            'created_by' => optional($request->user())->id,
        ]);
        $connection->setCredentials($validated['credentials'] ?? []);
        $connection->save();

        return $this->success((new EmailMarketingConnectionResource($connection))->resolve(), status: 201);
    }

    public function show(Request $request, EmailMarketingConnection $connection): JsonResponse
    {
        $workspace = $request->attributes->get('workspace');
        abort_unless($workspace && (string) $connection->workspace_id === (string) $workspace->id, 404);

        return $this->success((new EmailMarketingConnectionResource($connection))->resolve());
    }
}
