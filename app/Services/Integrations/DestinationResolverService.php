<?php

namespace App\Services\Integrations;

use App\Enums\ContentDestinationEnvironment;
use App\Enums\ContentDestinationStatus;
use App\Enums\ContentDestinationType;
use App\Models\ApiKey;
use App\Models\ContentDestination;
use App\Models\Workspace;
use Illuminate\Support\Str;

class DestinationResolverService
{
    public function resolve(
        Workspace $workspace,
        ?ApiKey $apiKey = null,
        ?string $destinationId = null,
    ): ContentDestination {
        $requestedId = trim((string) $destinationId);
        if ($requestedId !== '') {
            $destination = ContentDestination::query()
                ->where('workspace_id', $workspace->id)
                ->where('id', $requestedId)
                ->first();
            if ($destination) {
                return $destination;
            }
        }

        if ($apiKey?->content_destination_id) {
            $destination = ContentDestination::query()
                ->where('workspace_id', $workspace->id)
                ->where('id', $apiKey->content_destination_id)
                ->first();
            if ($destination) {
                return $destination;
            }
        }

        $existing = ContentDestination::query()
            ->where('workspace_id', $workspace->id)
            ->where('type', ContentDestinationType::API)
            ->where('status', ContentDestinationStatus::ACTIVE)
            ->orderBy('created_at')
            ->first();

        if ($existing) {
            return $existing;
        }

        return ContentDestination::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'name' => 'Default API Destination',
            'type' => ContentDestinationType::API,
            'status' => ContentDestinationStatus::ACTIVE,
            'environment' => ContentDestinationEnvironment::PRODUCTION,
            'default_language' => (string) ($workspace->default_content_language?->value ?? 'en'),
            'tracking_enabled' => true,
            'seo_audit_enabled' => true,
            'config' => [],
        ]);
    }
}
