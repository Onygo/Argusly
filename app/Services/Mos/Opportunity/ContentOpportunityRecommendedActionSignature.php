<?php

namespace App\Services\Mos\Opportunity;

use App\Models\ContentOpportunity;
use App\Models\Opportunity;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;

class ContentOpportunityRecommendedActionSignature
{
    public const CANONICAL_ACTION_TYPE = 'content_opportunity_review';

    public const SIGNATURE_VERSION = 'mos-content-opportunity-action:v1';

    public function supports(Model $source): bool
    {
        return $source instanceof ContentOpportunity
            || ($source instanceof Opportunity && $source->content_opportunity_id !== null);
    }

    public function signature(Model $source, ?Workspace $workspace, string $sourceGroup, string $actionType): string
    {
        if (! $this->supports($source)) {
            return $this->legacySignature($source, $workspace, $sourceGroup);
        }

        return sha1(implode('|', $this->signatureParts($source, $workspace, $actionType)));
    }

    /**
     * @return array<int,string>
     */
    public function signatureParts(Model $source, ?Workspace $workspace, string $actionType): array
    {
        $context = $this->bridgeContext($source);

        return [
            self::SIGNATURE_VERSION,
            'workspace:'.($workspace?->id ?? $context['workspace_id'] ?? 'global'),
            'site:'.($context['client_site_id'] ?? 'none'),
            'legacy_content_opportunity:'.($context['legacy_content_opportunity_id'] ?? 'missing'),
            'canonical_opportunity:'.($context['canonical_opportunity_id'] ?? 'missing'),
            'action_type:'.$this->canonicalActionType($actionType),
            'source_model:content_opportunity_bridge',
            'source_id:'.($context['legacy_content_opportunity_id'] ?? $context['canonical_opportunity_id'] ?? 'missing'),
            'dedupe_key:'.($context['dedupe_key'] ?? 'missing'),
        ];
    }

    public function legacySignature(Model $source, ?Workspace $workspace, string $sourceGroup): string
    {
        return sha1(implode('|', [
            $workspace?->id ?? 'global',
            $sourceGroup,
            $source::class,
            (string) $source->getKey(),
        ]));
    }

    private function canonicalActionType(string $actionType): string
    {
        return in_array($actionType, ['prepare_content_opportunity', 'review_opportunity'], true)
            ? self::CANONICAL_ACTION_TYPE
            : $actionType;
    }

    /**
     * @return array<string,string|null>
     */
    private function bridgeContext(Model $source): array
    {
        if ($source instanceof ContentOpportunity) {
            $canonical = Opportunity::query()
                ->where('content_opportunity_id', $source->id)
                ->first();

            return [
                'workspace_id' => $source->workspace_id ? (string) $source->workspace_id : null,
                'client_site_id' => $source->client_site_id ? (string) $source->client_site_id : null,
                'legacy_content_opportunity_id' => (string) $source->id,
                'canonical_opportunity_id' => $canonical?->id ? (string) $canonical->id : null,
                'dedupe_key' => $source->dedupe_hash ?: $canonical?->dedupe_hash,
            ];
        }

        /** @var Opportunity $source */
        $legacy = $source->contentOpportunity;

        return [
            'workspace_id' => $source->workspace_id ? (string) $source->workspace_id : null,
            'client_site_id' => $source->client_site_id ? (string) $source->client_site_id : null,
            'legacy_content_opportunity_id' => $source->content_opportunity_id ? (string) $source->content_opportunity_id : null,
            'canonical_opportunity_id' => (string) $source->id,
            'dedupe_key' => $legacy?->dedupe_hash ?: $source->dedupe_hash,
        ];
    }
}
