<?php

namespace App\Services\Attribution;

use App\Models\AttributionModelConfiguration;
use App\Models\Workspace;
use Illuminate\Support\Str;

class AttributionConfigurationResolver
{
    public function resolve(Workspace|string $workspace, string $modelKey = 'last_touch'): AttributionModelConfiguration
    {
        $workspaceId = $workspace instanceof Workspace ? (string) $workspace->id : (string) $workspace;

        $existing = AttributionModelConfiguration::query()
            ->forWorkspace($workspaceId)
            ->where('model_key', $modelKey)
            ->where('status', AttributionModelConfiguration::STATUS_ACTIVE)
            ->orderByDesc('is_default')
            ->oldest('created_at')
            ->first();

        if ($existing instanceof AttributionModelConfiguration) {
            return $existing;
        }

        return AttributionModelConfiguration::query()->create([
            'workspace_id' => $workspaceId,
            'key' => $modelKey.'-default',
            'label' => Str::headline($modelKey),
            'model_key' => $modelKey,
            'status' => AttributionModelConfiguration::STATUS_ACTIVE,
            'is_default' => true,
            'lookback_days' => 90,
            'settings_json' => [],
        ]);
    }
}
