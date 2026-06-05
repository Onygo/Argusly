<?php

namespace App\Http\Controllers\Api\V1\Headless;

use App\Http\Controllers\Api\V1\Concerns\RespondsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AsyncOperationResource;
use App\Models\AsyncOperationRun;
use Illuminate\Http\Request;

class OperationController extends Controller
{
    use RespondsWithApi;

    public function show(Request $request, string $operation)
    {
        $workspace = $request->attributes->get('workspace');

        $model = AsyncOperationRun::query()
            ->where('workspace_id', $workspace->id)
            ->where('id', $operation)
            ->firstOrFail();

        return $this->success((new AsyncOperationResource($model))->resolve());
    }
}
