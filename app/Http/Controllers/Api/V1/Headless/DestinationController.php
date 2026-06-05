<?php

namespace App\Http\Controllers\Api\V1\Headless;

use App\Http\Controllers\Api\V1\Concerns\RespondsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Headless\CreateDestinationRequest;
use App\Http\Requests\Api\V1\Headless\UpdateDestinationRequest;
use App\Http\Resources\Api\V1\ContentDestinationResource;
use App\Models\ContentDestination;
use App\Services\Integrations\ApiCapabilityService;
use App\Services\Integrations\DestinationBillingSiteService;
use App\Services\Integrations\LaravelConnectorDestinationConfigurator;
use Illuminate\Http\Request;

class DestinationController extends Controller
{
    use RespondsWithApi;

    public function index(Request $request)
    {
        $workspace = $request->attributes->get('workspace');

        $items = ContentDestination::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('deleted_at')
            ->with('latestSyncAttempt')
            ->orderByDesc('created_at')
            ->get();

        return $this->success(ContentDestinationResource::collection($items)->resolve());
    }

    public function store(
        CreateDestinationRequest $request,
        ApiCapabilityService $capabilities,
        LaravelConnectorDestinationConfigurator $configurator,
        DestinationBillingSiteService $billingSiteService,
    )
    {
        $workspace = $request->attributes->get('workspace');
        try {
            $capabilities->assertApiOnlyEnabled($workspace);
            $capabilities->assertCanCreateDestination($workspace);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), code: 'PLAN_LIMIT_REACHED', status: 422);
        }

        $validated = $request->validated();

        $destination = new ContentDestination(array_merge(
            collect($validated)->except(['config'])->all(),
            [
                'workspace_id' => $workspace->id,
                'created_by' => optional($request->user())->id,
            ]
        ));
        $destination->config = $configurator->mergeConfig($destination, $validated);
        $this->assertLaravelDestinationConfigComplete($destination);
        $destination->save();

        if ($destination->isLaravelConnector()) {
            $billingSiteService->ensureBillingSite($destination->fresh());
        }

        return $this->success((new ContentDestinationResource($destination->fresh('latestSyncAttempt')))->resolve(), status: 201);
    }

    public function show(Request $request, string $destination)
    {
        $workspace = $request->attributes->get('workspace');

        $model = ContentDestination::query()
            ->where('workspace_id', $workspace->id)
            ->where('id', $destination)
            ->firstOrFail();

        return $this->success((new ContentDestinationResource($model->load('latestSyncAttempt')))->resolve());
    }

    public function update(
        UpdateDestinationRequest $request,
        string $destination,
        LaravelConnectorDestinationConfigurator $configurator,
        DestinationBillingSiteService $billingSiteService,
    )
    {
        $workspace = $request->attributes->get('workspace');

        $model = ContentDestination::query()
            ->where('workspace_id', $workspace->id)
            ->where('id', $destination)
            ->firstOrFail();

        $validated = $request->validated();

        $model->fill(collect($validated)->except(['config'])->all());
        $model->config = $configurator->mergeConfig($model, $validated);
        $this->assertLaravelDestinationConfigComplete($model);
        $model->save();

        if ($model->isLaravelConnector()) {
            $billingSiteService->ensureBillingSite($model->fresh());
        }

        return $this->success((new ContentDestinationResource($model->fresh('latestSyncAttempt')))->resolve());
    }

    private function assertLaravelDestinationConfigComplete(ContentDestination $destination): void
    {
        if (! $destination->isLaravelConnector()) {
            return;
        }

        validator([
            'base_url' => $destination->laravelConnectorBaseUrl(),
            'site_id' => $destination->laravelConnectorSiteId(),
            'api_key' => $destination->laravelConnectorApiKey(),
        ], [
            'base_url' => ['required', 'url'],
            'site_id' => ['required', 'string'],
            'api_key' => ['required', 'string'],
        ], [
            'base_url.required' => 'Laravel connector base_url is required.',
            'site_id.required' => 'Laravel connector site_id is required.',
            'api_key.required' => 'Laravel connector api_key is required.',
        ])->validate();
    }
}
