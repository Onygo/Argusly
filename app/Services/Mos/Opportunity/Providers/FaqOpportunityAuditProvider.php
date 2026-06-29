<?php

namespace App\Services\Mos\Opportunity\Providers;

use App\Enums\FaqWorkflowStatus;
use App\Models\FaqOpportunityAudit;
use App\Services\Mos\Opportunity\CanonicalOpportunityCandidate;
use Illuminate\Database\Eloquent\Model;

class FaqOpportunityAuditProvider extends AbstractLegacyOpportunityProvider
{
    public function key(): string
    {
        return 'legacy-faq-opportunity-audits';
    }

    public function label(): string
    {
        return 'Legacy FAQ Opportunity Audits';
    }

    public function sourceModel(): ?string
    {
        return FaqOpportunityAudit::class;
    }

    public function supportedOpportunityTypes(): array
    {
        return ['ai_visibility_opportunity', 'content_gap'];
    }

    public function supportedLifecycleStates(): array
    {
        return FaqWorkflowStatus::values();
    }

    public function canEmitSignals(): bool
    {
        return true;
    }

    public function migrationReadiness(): string
    {
        return 'admin_workflow_context_missing';
    }

    public function classification(): string
    {
        return 'consolidation_candidate';
    }

    public function riskLevel(): string
    {
        return 'medium';
    }

    public function toCanonicalOpportunity(Model $source): CanonicalOpportunityCandidate
    {
        $pageTitle = $this->stringValue($source->getAttribute('page_title'));
        $pageSlug = $this->stringValue($source->getAttribute('page_slug'));

        return new CanonicalOpportunityCandidate(
            title: $pageTitle ? 'FAQ opportunity: '.$pageTitle : null,
            description: $this->stringValue($source->getAttribute('score_rationale')),
            type: 'ai_visibility_opportunity',
            source: $this->sourceType(),
            sourceModel: $source::class,
            sourceId: $source->getKey(),
            priority: $this->floatValue($source->getAttribute('faq_opportunity_score')),
            confidence: $this->floatValue($source->getAttribute('faq_coverage_score')),
            impact: $this->floatValue($source->getAttribute('ai_visibility_impact_score')),
            effort: null,
            businessValue: $this->floatValue($source->getAttribute('conversion_impact_score')),
            evidence: $this->listValue($source->getAttribute('missing_questions')),
            recommendedActions: array_merge(
                $this->listValue($source->getAttribute('generated_faqs')),
                $this->listValue($source->getAttribute('suggested_internal_links')),
                $this->listValue($source->getAttribute('suggested_ctas')),
            ),
            lifecycleStatus: $this->stringValue($source->getAttribute('status')),
            context: array_filter([
                'page_type' => $source->getAttribute('page_type'),
                'page_slug' => $pageSlug,
                'locale' => $source->getAttribute('locale'),
                'sector' => $source->getAttribute('sector'),
                'solution_type' => $source->getAttribute('solution_type'),
            ], static fn (mixed $value): bool => $value !== null),
            relatedReferences: $this->references([
                'page_slug' => $pageSlug,
                'created_by' => $source->getAttribute('created_by'),
            ]),
            dedupeKey: $pageSlug ? hash('sha256', implode('|', [
                $this->key(),
                $pageSlug,
                $source->getAttribute('locale'),
            ])) : null,
            missingFields: $this->missingFields($source),
        );
    }

    public function missingFields(Model $source): array
    {
        return $this->missingFromMap([
            'page_title' => $source->getAttribute('page_title'),
            'page_slug' => $source->getAttribute('page_slug'),
            'locale' => $source->getAttribute('locale'),
            'status' => $source->getAttribute('status'),
            'faq_opportunity_score' => $source->getAttribute('faq_opportunity_score'),
            'workspace_or_organization_context' => null,
        ]);
    }
}
