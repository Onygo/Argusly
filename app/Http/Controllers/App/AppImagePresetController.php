<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\ImagePreset;
use App\Services\ImagePresetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AppImagePresetController extends Controller
{
    public function __construct(
        private readonly ImagePresetService $presetService,
    ) {}

    /**
     * List all presets for the organization.
     */
    public function index(Request $request): View
    {
        $organizationId = $this->organizationId($request);

        $presets = ImagePreset::getAllForOrganization($organizationId);

        return view('app.settings.image-presets.index', [
            'presets' => $presets,
        ]);
    }

    /**
     * Show the create form.
     */
    public function create(Request $request): View
    {
        $this->organizationId($request); // Ensure org context

        return view('app.settings.image-presets.create');
    }

    /**
     * Store a new preset.
     */
    public function store(Request $request): RedirectResponse
    {
        $organizationId = $this->organizationId($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'instructions' => ['required', 'string', 'max:5000'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $preset = $this->presetService->createPreset(
            $organizationId,
            $data,
            $request->user()->id
        );

        return redirect()
            ->route('app.settings.image-presets.index')
            ->with('status', 'Image preset "' . $preset->name . '" created.');
    }

    /**
     * Show the edit form.
     */
    public function edit(Request $request, ImagePreset $imagePreset): View
    {
        $this->assertPresetInOrganization($request, $imagePreset);

        return view('app.settings.image-presets.edit', [
            'preset' => $imagePreset,
        ]);
    }

    /**
     * Update an existing preset.
     */
    public function update(Request $request, ImagePreset $imagePreset): RedirectResponse
    {
        $this->assertPresetInOrganization($request, $imagePreset);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'instructions' => ['required', 'string', 'max:5000'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $this->presetService->updatePreset($imagePreset, $data);

        return redirect()
            ->route('app.settings.image-presets.index')
            ->with('status', 'Image preset updated.');
    }

    /**
     * Delete a preset.
     */
    public function destroy(Request $request, ImagePreset $imagePreset): RedirectResponse
    {
        $this->assertPresetInOrganization($request, $imagePreset);

        $name = $imagePreset->name;
        $this->presetService->deletePreset($imagePreset);

        return redirect()
            ->route('app.settings.image-presets.index')
            ->with('status', 'Image preset "' . $name . '" deleted.');
    }

    /**
     * Set a preset as the default.
     */
    public function setDefault(Request $request, ImagePreset $imagePreset): RedirectResponse
    {
        $this->assertPresetInOrganization($request, $imagePreset);

        $this->presetService->setDefault($imagePreset);

        return redirect()
            ->route('app.settings.image-presets.index')
            ->with('status', 'Image preset "' . $imagePreset->name . '" is now the default.');
    }

    // =========================================================================
    // JSON API endpoints (for AJAX/modal usage)
    // =========================================================================

    /**
     * List presets as JSON.
     */
    public function apiIndex(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        $presets = ImagePreset::getAllForOrganization($organizationId)
            ->map(fn (ImagePreset $preset) => [
                'id' => $preset->id,
                'name' => $preset->name,
                'instructions' => $preset->instructions,
                'instructions_preview' => $preset->getInstructionsPreview(80),
                'is_default' => $preset->is_default,
                'created_at' => $preset->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'data' => $presets,
        ]);
    }

    /**
     * Store preset via JSON.
     */
    public function apiStore(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        try {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'instructions' => ['required', 'string', 'max:5000'],
                'is_default' => ['nullable', 'boolean'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        $preset = $this->presetService->createPreset(
            $organizationId,
            $data,
            $request->user()->id
        );

        return response()->json([
            'data' => [
                'id' => $preset->id,
                'name' => $preset->name,
                'instructions' => $preset->instructions,
                'is_default' => $preset->is_default,
            ],
            'message' => 'Preset created successfully.',
        ], 201);
    }

    /**
     * Update preset via JSON.
     */
    public function apiUpdate(Request $request, ImagePreset $imagePreset): JsonResponse
    {
        $this->assertPresetInOrganization($request, $imagePreset);

        try {
            $data = $request->validate([
                'name' => ['sometimes', 'required', 'string', 'max:255'],
                'instructions' => ['sometimes', 'required', 'string', 'max:5000'],
                'is_default' => ['nullable', 'boolean'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        $preset = $this->presetService->updatePreset($imagePreset, $data);

        return response()->json([
            'data' => [
                'id' => $preset->id,
                'name' => $preset->name,
                'instructions' => $preset->instructions,
                'is_default' => $preset->is_default,
            ],
            'message' => 'Preset updated successfully.',
        ]);
    }

    /**
     * Delete preset via JSON.
     */
    public function apiDestroy(Request $request, ImagePreset $imagePreset): JsonResponse
    {
        $this->assertPresetInOrganization($request, $imagePreset);

        $this->presetService->deletePreset($imagePreset);

        return response()->json([
            'message' => 'Preset deleted successfully.',
        ]);
    }

    /**
     * Set default via JSON.
     */
    public function apiSetDefault(Request $request, ImagePreset $imagePreset): JsonResponse
    {
        $this->assertPresetInOrganization($request, $imagePreset);

        $preset = $this->presetService->setDefault($imagePreset);

        return response()->json([
            'data' => [
                'id' => $preset->id,
                'name' => $preset->name,
                'is_default' => $preset->is_default,
            ],
            'message' => 'Preset set as default.',
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function assertPresetInOrganization(Request $request, ImagePreset $preset): void
    {
        $organizationId = $this->organizationId($request);

        if ((int) $preset->organization_id !== $organizationId) {
            abort(404);
        }
    }

    private function organizationId(Request $request): int
    {
        $organizationId = (int) $request->user()->organization_id;

        if ($organizationId < 1) {
            abort(403, 'No organization context available.');
        }

        return $organizationId;
    }
}
