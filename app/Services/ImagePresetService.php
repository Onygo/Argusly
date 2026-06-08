<?php

namespace App\Services;

use App\Models\ImagePreset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImagePresetService
{
    /**
     * Create a new image preset.
     *
     * If this is the first preset for the organization, it will be marked as default.
     *
     * @param array{name: string, instructions: string, is_default?: bool} $data
     */
    public function createPreset(int $organizationId, array $data, ?int $userId = null): ImagePreset
    {
        return DB::transaction(function () use ($organizationId, $data, $userId) {
            $isFirstPreset = ImagePreset::query()
                ->withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->doesntExist();

            // If first preset or explicitly set as default, handle default switching
            $shouldBeDefault = $isFirstPreset || ($data['is_default'] ?? false);

            if ($shouldBeDefault && ! $isFirstPreset) {
                $this->clearDefaultForOrganization($organizationId);
            }

            return ImagePreset::query()->create([
                'id' => (string) Str::uuid(),
                'organization_id' => $organizationId,
                'name' => trim($data['name']),
                'instructions' => trim($data['instructions']),
                'is_default' => $shouldBeDefault,
                'created_by' => $userId,
            ]);
        });
    }

    /**
     * Update an existing preset.
     *
     * @param array{name?: string, instructions?: string, is_default?: bool} $data
     */
    public function updatePreset(ImagePreset $preset, array $data): ImagePreset
    {
        return DB::transaction(function () use ($preset, $data) {
            $updates = [];

            if (isset($data['name'])) {
                $updates['name'] = trim($data['name']);
            }

            if (isset($data['instructions'])) {
                $updates['instructions'] = trim($data['instructions']);
            }

            if (isset($data['is_default']) && $data['is_default'] === true && ! $preset->is_default) {
                $this->clearDefaultForOrganization((int) $preset->organization_id);
                $updates['is_default'] = true;
            }

            if ($updates !== []) {
                $preset->update($updates);
            }

            return $preset->fresh();
        });
    }

    /**
     * Delete a preset.
     *
     * If the deleted preset was the default, assign default to another preset if available.
     */
    public function deletePreset(ImagePreset $preset): bool
    {
        return DB::transaction(function () use ($preset) {
            $wasDefault = $preset->is_default;
            $organizationId = (int) $preset->organization_id;

            $preset->delete();

            // If deleted preset was default, assign default to another preset
            if ($wasDefault) {
                $nextPreset = ImagePreset::query()
                    ->withoutGlobalScopes()
                    ->where('organization_id', $organizationId)
                    ->orderBy('created_at')
                    ->first();

                if ($nextPreset) {
                    $nextPreset->update(['is_default' => true]);
                }
            }

            return true;
        });
    }

    /**
     * Set a preset as the default for its organization.
     */
    public function setDefault(ImagePreset $preset): ImagePreset
    {
        return DB::transaction(function () use ($preset) {
            if ($preset->is_default) {
                return $preset;
            }

            $this->clearDefaultForOrganization((int) $preset->organization_id);

            $preset->update(['is_default' => true]);

            return $preset->fresh();
        });
    }

    /**
     * Remove default status from all presets in an organization.
     */
    private function clearDefaultForOrganization(int $organizationId): void
    {
        ImagePreset::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    /**
     * Resolve the preset to use for image generation.
     *
     * Resolution order:
     * 1. Specific preset ID if provided and belongs to org
     * 2. Organization default preset
     * 3. null (no preset - use system defaults)
     */
    public function resolvePresetForGeneration(int $organizationId, ?string $presetId = null): ?ImagePreset
    {
        // If specific preset requested, validate it belongs to org
        if ($presetId !== null && trim($presetId) !== '') {
            $preset = ImagePreset::query()
                ->withoutGlobalScopes()
                ->where('id', $presetId)
                ->where('organization_id', $organizationId)
                ->first();

            if ($preset) {
                return $preset;
            }
        }

        // Fall back to organization default
        return ImagePreset::getDefaultForOrganization($organizationId);
    }

    /**
     * Get the system default instructions when no preset is selected.
     */
    public function getSystemDefaultInstructions(): string
    {
        return config('argusly.images.default_style_instructions', implode("\n", [
            'Clean and modern aesthetic',
            'High contrast with professional lighting',
            'No text overlays or logos',
            'Suitable for blog hero images',
            'Contemporary and visually engaging',
        ]));
    }

    /**
     * Build the complete style instructions for image generation.
     *
     * Combines preset instructions with system defaults as fallback.
     */
    public function buildStyleInstructions(?ImagePreset $preset): string
    {
        if ($preset !== null) {
            return trim($preset->instructions);
        }

        return $this->getSystemDefaultInstructions();
    }

    /**
     * Get all presets for an organization.
     *
     * Returns presets ordered by default first, then by name.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ImagePreset>
     */
    public function getOrganizationPresets(int $organizationId)
    {
        return ImagePreset::getAllForOrganization($organizationId);
    }

    /**
     * Get the default preset for an organization.
     */
    public function getDefaultPreset(int $organizationId): ?ImagePreset
    {
        return ImagePreset::getDefaultForOrganization($organizationId);
    }

    /**
     * Get presets formatted as dropdown options.
     *
     * Returns an array of presets with id, name, instructions, and is_default.
     * Useful for populating select dropdowns in the UI.
     *
     * @return array<int, array{id: string, name: string, instructions: string, is_default: bool}>
     */
    public function getPresetOptions(int $organizationId): array
    {
        return ImagePreset::getAllForOrganization($organizationId)
            ->map(fn (ImagePreset $preset) => [
                'id' => (string) $preset->id,
                'name' => (string) $preset->name,
                'instructions' => (string) $preset->instructions,
                'is_default' => (bool) $preset->is_default,
            ])
            ->values()
            ->all();
    }
}
