<?php

namespace App\Services\CompanyIntelligence;

use App\DTO\CompanyIntelligence\CompanyIntelligenceProfileData;
use App\Models\CompanyIntelligenceProfile;
use App\Models\Workspace;

class CompanyIntelligenceContextService
{
    public function __construct(
        private readonly CompanyIntelligenceNormalizer $normalizer,
    ) {
    }

    public function forWorkspace(Workspace|string $workspace, ?string $brandKey = null): ?CompanyIntelligenceProfileData
    {
        $workspaceId = $workspace instanceof Workspace ? (string) $workspace->id : (string) $workspace;

        $profile = CompanyIntelligenceProfile::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', CompanyIntelligenceProfile::STATUS_ACTIVE)
            ->when(
                $brandKey,
                fn ($query) => $query->where('brand_key', $brandKey),
                fn ($query) => $query->orderByDesc('is_default')->orderByDesc('completeness_score'),
            )
            ->first();

        return $profile ? $this->normalizer->normalize($profile) : null;
    }

    /**
     * @return array<string,mixed>
     */
    public function promptContext(Workspace|string $workspace, ?string $brandKey = null): array
    {
        $profile = $this->forWorkspace($workspace, $brandKey);

        if (! $profile) {
            return [
                'available' => false,
                'schema_version' => 'company_intelligence.v1',
                'reason' => 'No active company intelligence profile is available for this workspace.',
            ];
        }

        return [
            'available' => true,
            'schema_version' => 'company_intelligence.v1',
            'completeness_score' => $profile->completenessScore,
            'payload_hash' => $profile->payloadHash,
            'company_intelligence' => $profile->payload,
        ];
    }
}
