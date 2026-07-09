<?php

namespace App\Services\SignalIntelligence;

use App\Models\ClientSite;
use App\Models\SignalEntity;
use App\Models\Workspace;
use App\Support\Intelligence\CanonicalEntityReference;

class SignalEntityResolver
{
    /**
     * @param array<string,mixed> $metadata
     */
    public function resolve(
        Workspace $workspace,
        string $entityType,
        string $entityName,
        ?ClientSite $clientSite = null,
        array $metadata = []
    ): SignalEntity {
        $reference = CanonicalEntityReference::fromName($entityType, $entityName);
        $entityKey = $reference->key;

        $entity = SignalEntity::query()->firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'entity_type' => $entityType,
                'entity_key' => $entityKey,
            ],
            [
                'organization_id' => $workspace->organization_id,
                'client_site_id' => $clientSite?->id,
                'entity_name' => $entityName,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'mention_count' => 0,
                'signal_count' => 0,
                'metadata' => $metadata,
            ]
        );

        $updates = ['last_seen_at' => now()];

        if ($clientSite && ! $entity->client_site_id) {
            $updates['client_site_id'] = $clientSite->id;
        }

        if ($metadata !== []) {
            $updates['metadata'] = array_replace_recursive((array) $entity->metadata, $metadata);
        }

        $entity->forceFill($updates)->save();

        return $entity->refresh();
    }

    public function incrementMentionCount(SignalEntity $entity, int $by = 1): SignalEntity
    {
        $entity->increment('mention_count', $by, ['last_seen_at' => now()]);

        return $entity->refresh();
    }

    public function incrementSignalCount(SignalEntity $entity, int $by = 1): SignalEntity
    {
        $entity->increment('signal_count', $by, ['last_seen_at' => now()]);

        return $entity->refresh();
    }

    public function entityKey(string $entityName): string
    {
        return CanonicalEntityReference::keyForName($entityName);
    }
}
