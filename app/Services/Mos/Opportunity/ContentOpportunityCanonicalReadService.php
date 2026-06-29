<?php

namespace App\Services\Mos\Opportunity;

use App\Models\ContentOpportunity;
use App\Models\Opportunity;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class ContentOpportunityCanonicalReadService
{
    public function read(ContentOpportunity $contentOpportunity): ContentOpportunityCanonicalReadModel
    {
        $canonical = $this->linkedCanonicalOpportunity($contentOpportunity);

        return new ContentOpportunityCanonicalReadModel(
            legacyContentOpportunityId: (string) $contentOpportunity->id,
            canonicalOpportunityId: $canonical?->id ? (string) $canonical->id : null,
            title: $this->value('title', $canonical?->title, $contentOpportunity->title, $provenance),
            type: $this->legacyValue('type', $contentOpportunity->type, $provenance),
            status: $this->legacyValue('status', $contentOpportunity->status, $provenance),
            priority: $this->floatValue('priority', $canonical?->priority_score, $contentOpportunity->priority_score, $provenance),
            confidence: $this->floatValue('confidence', $canonical?->confidence_score, $contentOpportunity->confidence_score, $provenance),
            impact: $this->value('impact', $canonical?->impact_score, $contentOpportunity->expected_impact, $provenance),
            effort: $this->floatValue('effort', $canonical?->effort_score, null, $provenance),
            urgency: $this->floatValue('urgency', $canonical?->urgency_score, $contentOpportunity->urgency_score, $provenance),
            businessValue: $this->floatValue(
                'business_value',
                data_get($canonical?->score_breakdown, 'business_value'),
                $contentOpportunity->business_value_score,
                $provenance,
            ),
            recommendedActions: $this->arrayValue(
                'recommended_actions',
                $canonical?->recommended_actions,
                $this->legacyRecommendedActions($contentOpportunity),
                $provenance,
            ),
            evidence: $this->arrayValue(
                'evidence',
                $canonical?->evidence,
                $this->legacyEvidence($contentOpportunity),
                $provenance,
            ),
            workspaceContext: [
                'organization_id' => $this->value('organization_id', $canonical?->organization_id, $contentOpportunity->organization_id, $provenance),
                'workspace_id' => $this->value('workspace_id', $canonical?->workspace_id, $contentOpportunity->workspace_id, $provenance),
                'client_site_id' => $this->value('client_site_id', $canonical?->client_site_id, $contentOpportunity->client_site_id, $provenance),
            ],
            provenance: $provenance ?? [],
            legacyFields: [
                'id' => (string) $contentOpportunity->id,
                'title' => $contentOpportunity->title,
                'type' => $contentOpportunity->type,
                'status' => $contentOpportunity->status,
                'topic' => data_get($contentOpportunity->normalized_payload, 'candidate.topic'),
                'funnel_stage' => $contentOpportunity->funnel_stage,
                'primary_search_intent' => $contentOpportunity->primary_search_intent,
                'reasoning' => $contentOpportunity->reasoning,
                'angle' => $contentOpportunity->angle,
                'related_entities' => (array) $contentOpportunity->related_entities,
                'freshness_status' => $contentOpportunity->freshness_status,
            ],
        );
    }

    /**
     * @param  EloquentCollection<int, ContentOpportunity>  $contentOpportunities
     * @return Collection<int, ContentOpportunityCanonicalReadModel>
     */
    public function readMany(EloquentCollection $contentOpportunities): Collection
    {
        return $contentOpportunities
            ->loadMissing('workspace')
            ->map(fn (ContentOpportunity $contentOpportunity): ContentOpportunityCanonicalReadModel => $this->read($contentOpportunity));
    }

    private function linkedCanonicalOpportunity(ContentOpportunity $contentOpportunity): ?Opportunity
    {
        return Opportunity::query()
            ->where('content_opportunity_id', $contentOpportunity->id)
            ->first();
    }

    /**
     * @param  array<string, string>|null  $provenance
     */
    private function value(string $field, mixed $canonical, mixed $legacy, ?array &$provenance): mixed
    {
        if ($canonical !== null && (! is_string($canonical) || trim($canonical) !== '')) {
            $provenance[$field] = 'canonical';

            return $canonical;
        }

        $provenance[$field] = 'legacy';

        return $legacy;
    }

    /**
     * @param  array<string, string>|null  $provenance
     */
    private function legacyValue(string $field, mixed $legacy, ?array &$provenance): mixed
    {
        $provenance[$field] = 'legacy';

        return $legacy;
    }

    /**
     * @param  array<string, string>|null  $provenance
     */
    private function floatValue(string $field, mixed $canonical, mixed $legacy, ?array &$provenance): ?float
    {
        $value = $this->value($field, $canonical, $legacy, $provenance);

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param  array<string, string>|null  $provenance
     * @return array<int, mixed>
     */
    private function arrayValue(string $field, mixed $canonical, mixed $legacy, ?array &$provenance): array
    {
        $value = $this->value($field, $canonical, $legacy, $provenance);

        return is_array($value) ? array_values(array_filter($value)) : [];
    }

    /**
     * @return array<int, mixed>
     */
    private function legacyRecommendedActions(ContentOpportunity $contentOpportunity): array
    {
        return array_values(array_filter([
            $contentOpportunity->suggested_cta,
            $contentOpportunity->suggested_schema,
            $contentOpportunity->localization_recommendation,
        ]));
    }

    /**
     * @return array<int, mixed>
     */
    private function legacyEvidence(ContentOpportunity $contentOpportunity): array
    {
        return array_values(array_filter(array_merge((array) $contentOpportunity->source_signals, [[
            'type' => 'legacy_content_opportunity',
            'source_model' => ContentOpportunity::class,
            'source_id' => (string) $contentOpportunity->id,
            'reasoning' => $contentOpportunity->reasoning,
            'why_this_matters' => $contentOpportunity->why_this_matters,
            'why_now' => $contentOpportunity->why_now,
        ]])));
    }
}
