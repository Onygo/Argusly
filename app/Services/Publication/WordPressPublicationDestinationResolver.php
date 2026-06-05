<?php

namespace App\Services\Publication;

use App\Enums\ContentDestinationEnvironment;
use App\Enums\ContentDestinationStatus;
use App\Enums\ContentDestinationType;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\Draft;

class WordPressPublicationDestinationResolver
{
    public function resolveForContent(Content $content, ?Draft $draft = null): ?ContentDestination
    {
        $content->loadMissing('contentDestination', 'clientSite.workspace');

        $existing = $content->contentDestination;
        if ($existing instanceof ContentDestination && $existing->typeValue() === ContentDestinationType::WORDPRESS->value) {
            return $existing;
        }

        $site = $content->clientSite;
        if (! $site || ClientSite::normalizeType((string) $site->type) !== ClientSite::TYPE_WORDPRESS) {
            return null;
        }

        $destination = ContentDestination::query()
            ->where('workspace_id', $site->workspace_id)
            ->where('type', ContentDestinationType::WORDPRESS->value)
            ->where('config->billing_client_site_id', (string) $site->id)
            ->first();

        if (! $destination) {
            $destination = ContentDestination::query()->create([
                'workspace_id' => $site->workspace_id,
                'name' => trim((string) ($site->name ?: 'WordPress Destination')),
                'type' => ContentDestinationType::WORDPRESS->value,
                'status' => ContentDestinationStatus::ACTIVE->value,
                'environment' => ContentDestinationEnvironment::PRODUCTION->value,
                'default_language' => (string) ($draft?->language?->value ?? 'en'),
                'config' => [
                    'billing_client_site_id' => (string) $site->id,
                    'base_url' => (string) ($site->base_url ?: $site->site_url),
                ],
            ]);
        }

        if ((string) ($content->content_destination_id ?? '') !== (string) $destination->id) {
            $content->forceFill(['content_destination_id' => $destination->id])->save();
        }

        if ($draft && (string) ($draft->content_destination_id ?? '') !== (string) $destination->id) {
            $draft->forceFill(['content_destination_id' => $destination->id])->save();
        }

        return $destination;
    }
}
