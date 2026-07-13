<?php

namespace App\Services\BrandIntelligence;

use App\Models\BrandContext;
use App\Models\BrandVoice;
use App\Models\Workspace;
use App\Services\CompanyIntelligence\CompanyIntelligenceContextService;
use Illuminate\Support\Arr;

class BrandIntelligenceContextService
{
    public function __construct(
        private readonly CompanyIntelligenceContextService $companyIntelligence,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function snapshotForWorkspace(Workspace|string $workspace, ?string $brandKey = null): array
    {
        $workspaceId = $workspace instanceof Workspace ? (string) $workspace->id : (string) $workspace;
        $workspaceModel = $workspace instanceof Workspace ? $workspace : Workspace::query()->find($workspaceId);
        $profile = $this->companyIntelligence->profileForWorkspace($workspace, $brandKey);
        $company = $profile ? $this->companyIntelligence->forWorkspace($workspace, $brandKey) : null;
        $payload = $company?->payload ?? [];
        $brandVoice = $this->defaultBrandVoice($workspaceId);
        $brandContext = $this->latestBrandContext($workspaceId);

        if (! $company && ! $brandVoice && ! $brandContext) {
            return [
                'available' => false,
                'schema_version' => 'brand_intelligence.snapshot.v1',
                'reason' => 'No active company intelligence, brand voice or brand context is available for this workspace.',
                'sources' => [],
            ];
        }

        return $this->prune([
            'available' => true,
            'schema_version' => 'brand_intelligence.snapshot.v1',
            'workspace' => [
                'id' => $workspaceId,
                'organization_id' => $workspaceModel?->organization_id,
            ],
            'sources' => [
                'company_intelligence_profile_id' => $profile?->id,
                'company_intelligence_payload_hash' => $company?->payloadHash,
                'company_intelligence_completeness_score' => $company?->completenessScore,
                'brand_key' => data_get($payload, 'metadata.brand_key'),
                'brand_voice_id' => $brandVoice?->id,
                'brand_context_id' => $brandContext?->id,
            ],
            'company' => [
                'name' => data_get($payload, 'business.company_name'),
                'description' => data_get($payload, 'business.company_description'),
                'market_category' => data_get($payload, 'business.market_category'),
                'positioning' => data_get($payload, 'business.positioning'),
                'uvp' => data_get($payload, 'business.uvp'),
                'products_services' => $this->limitList(data_get($payload, 'business.products_services', [])),
                'regions' => $this->limitList(data_get($payload, 'business.regions', [])),
                'locales' => $this->limitList(data_get($payload, 'business.locales', [])),
            ],
            'audience' => [
                'icps' => $this->limitList(data_get($payload, 'audience.icps', [])),
                'personas' => $this->limitList(data_get($payload, 'audience.personas', [])),
                'buyer_roles' => $this->limitList(data_get($payload, 'audience.buyer_roles', [])),
                'pain_points' => $this->limitList(data_get($payload, 'audience.pain_points', [])),
            ],
            'voice' => [
                'name' => $brandVoice?->name,
                'tone_of_voice' => data_get($payload, 'brand.tone_of_voice') ?: $brandVoice?->tone_of_voice,
                'writing_style' => $brandVoice?->writing_style,
                'style_guide' => $brandVoice?->style_guide,
                'default_language' => $brandVoice?->default_language,
                'default_tone' => $brandVoice?->default_tone,
                'messaging_rules' => $this->limitList(data_get($payload, 'brand.messaging_rules', [])),
                'preferred_terminology' => $this->limitList($brandVoice?->preferredTerminologyArray() ?? []),
                'disallowed_terminology' => $this->limitList(array_merge(
                    Arr::wrap(data_get($payload, 'brand.banned_phrases', [])),
                    $brandVoice?->disallowedTerminologyArray() ?? [],
                )),
            ],
            'proof' => [
                'proof_points' => $this->limitList(data_get($payload, 'brand.proof_points', [])),
                'brand_differentiators' => $this->limitList(data_get($payload, 'brand.brand_differentiators', [])),
            ],
            'entities' => [
                'primary_topics' => $this->limitList(data_get($payload, 'seo_aeo.primary_topics', [])),
                'authority_areas' => $this->limitList(data_get($payload, 'seo_aeo.authority_areas', [])),
                'target_entities' => $this->limitList(data_get($payload, 'seo_aeo.target_entities', [])),
                'strategic_keywords' => $this->limitList(data_get($payload, 'seo_aeo.strategic_keywords', [])),
                'competitors' => [
                    'direct' => $this->limitList(data_get($payload, 'competitors.direct', [])),
                    'indirect' => $this->limitList(data_get($payload, 'competitors.indirect', [])),
                    'aspirational' => $this->limitList(data_get($payload, 'competitors.aspirational', [])),
                ],
            ],
            'governance' => [
                'status' => data_get($payload, 'metadata.status'),
                'is_default' => data_get($payload, 'metadata.is_default'),
                'source_type' => data_get($payload, 'metadata.source_type'),
                'updated_at' => $profile?->updated_at?->toIso8601String(),
            ],
        ]);
    }

    private function defaultBrandVoice(string $workspaceId): ?BrandVoice
    {
        return BrandVoice::query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('is_default')
            ->latest()
            ->first();
    }

    private function latestBrandContext(string $workspaceId): ?BrandContext
    {
        return BrandContext::query()
            ->where('workspace_id', $workspaceId)
            ->latest()
            ->first();
    }

    /**
     * @return array<int,string>
     */
    private function limitList(mixed $items, int $limit = 6): array
    {
        return collect(Arr::wrap($items))
            ->flatten()
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->unique(fn (string $item): string => mb_strtolower($item))
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function prune(array $payload): array
    {
        return collect($payload)
            ->map(function (mixed $value): mixed {
                if (is_array($value)) {
                    return $this->prune($value);
                }

                return $value;
            })
            ->reject(fn (mixed $value): bool => $value === null || $value === '' || $value === [])
            ->all();
    }
}
