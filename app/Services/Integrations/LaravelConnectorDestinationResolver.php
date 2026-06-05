<?php

namespace App\Services\Integrations;

use App\Enums\ContentDestinationStatus;
use App\Enums\ContentDestinationType;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentDestination;

class LaravelConnectorDestinationResolver
{
    public function resolveForContent(Content $content): ?ContentDestination
    {
        $content->loadMissing('contentDestination', 'clientSite');

        $destination = $content->contentDestination;
        if ($destination instanceof ContentDestination && $destination->isLaravelConnector()) {
            return $this->isActiveLaravelDestination($destination) ? $destination : null;
        }

        $clientSite = $content->clientSite;
        if (! $clientSite) {
            return null;
        }

        return ContentDestination::query()
            ->where('workspace_id', $content->workspace_id)
            ->where('type', ContentDestinationType::LARAVEL)
            ->where('status', ContentDestinationStatus::ACTIVE)
            ->where('config->billing_client_site_id', (string) $clientSite->id)
            ->orderBy('created_at')
            ->first();
    }

    public function resolveForSite(ClientSite $site): ?ContentDestination
    {
        return ContentDestination::query()
            ->where('workspace_id', $site->workspace_id)
            ->where('type', ContentDestinationType::LARAVEL)
            ->where('status', ContentDestinationStatus::ACTIVE)
            ->where('config->billing_client_site_id', (string) $site->id)
            ->orderBy('created_at')
            ->first();
    }

    private function isActiveLaravelDestination(ContentDestination $destination): bool
    {
        return $destination->isLaravelConnector()
            && ($destination->status?->value ?? $destination->status) === ContentDestinationStatus::ACTIVE->value;
    }
}
