<?php

namespace App\Http\Controllers\App\Api;

use App\Http\Controllers\Controller;
use App\Services\BrandContext\FieldTransformationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AppBrandFieldActionsController extends Controller
{
    /**
     * Transform a field value using AI.
     */
    public function transform(Request $request, FieldTransformationService $transformationService): JsonResponse
    {
        Gate::authorize('manage-organization');

        $data = $request->validate([
            'field_value' => ['required', 'string', 'min:1', 'max:10000'],
            'action' => ['required', 'string', Rule::in(array_keys(FieldTransformationService::ACTIONS))],
            'field_context' => ['nullable', 'string', 'max:255'],
        ]);

        $organization = $request->user()->organization;
        $brandContext = $organization ? [
            'organization' => [
                'name' => $organization->name,
                'industry' => $organization->industry ?? '',
            ],
        ] : [];

        $transformedValue = $transformationService->transform(
            $data['field_value'],
            $data['action'],
            $data['field_context'] ?? null,
            $brandContext
        );

        return response()->json([
            'success' => true,
            'transformed_value' => $transformedValue,
            'action' => $data['action'],
        ]);
    }
}
