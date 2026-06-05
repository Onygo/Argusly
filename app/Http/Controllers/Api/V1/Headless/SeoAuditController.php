<?php

namespace App\Http\Controllers\Api\V1\Headless;

use App\Actions\SeoAudits\StartSeoAuditAction;
use App\Http\Controllers\Api\V1\Concerns\RespondsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Headless\StartSeoAuditRequest;
use App\Http\Resources\Api\V1\AsyncOperationResource;
use App\Http\Resources\Api\V1\SeoAuditResource;
use App\Models\SeoAudit;
use Illuminate\Http\Request;

class SeoAuditController extends Controller
{
    use RespondsWithApi;

    public function store(StartSeoAuditRequest $request, StartSeoAuditAction $action)
    {
        $workspace = $request->attributes->get('workspace');
        $apiKey = $request->attributes->get('apiKey');
        $validated = $request->validated();

        $operation = $action->execute(
            workspace: $workspace,
            apiKey: $apiKey,
            contentDestinationId: $validated['content_destination_id'] ?? null,
            maxPages: (int) ($validated['max_pages'] ?? 50),
        );

        return $this->success((new AsyncOperationResource($operation))->resolve(), status: 202);
    }

    public function show(Request $request, string $audit)
    {
        $workspace = $request->attributes->get('workspace');

        $model = SeoAudit::query()
            ->where('workspace_id', $workspace->id)
            ->where('id', $audit)
            ->firstOrFail();

        return $this->success((new SeoAuditResource($model))->resolve());
    }
}
