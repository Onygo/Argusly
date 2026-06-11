<?php

namespace App\Services\Growth;

use App\Enums\ContentDestinationStatus;
use App\Enums\GrowthAssetType;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ProgrammaticCluster;
use App\Models\ProgrammaticDraftReview;
use App\Models\ProgrammaticPublicationReadiness;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProgrammaticPublicationReadinessService
{
    public function checkContent(Content $content): ProgrammaticPublicationReadiness
    {
        $content->loadMissing(['contentDestination', 'publicationReadiness', 'workspace']);
        $review = $this->reviewForContent($content);
        $review?->loadMissing(['request.blueprint', 'draft', 'brief', 'cluster.items', 'item']);

        $type = $this->growthAssetType($content, $review);
        $schemaRecommendations = $this->schemaRecommendations($content, $review);
        $internalLinkingPlan = $this->internalLinkingPlan($content, $review);
        $primaryKeyword = trim((string) ($content->primary_keyword ?: data_get($content->aeo_breakdown, 'primary_keyword') ?: data_get($review?->request?->metadata, 'primary_keyword')));
        $destination = $this->destinationFor($content);
        $clusterItemsCount = $review?->cluster?->items?->count() ?? 0;

        $checks = [
            'seo' => [
                'title_present' => trim((string) $content->title) !== '',
                'slug_present' => trim((string) ($content->canonical_url_key ?: $content->publish_url_key ?: $content->external_key)) !== '',
                'meta_title_present_or_derivable' => trim((string) ($content->seo_title ?: $content->title)) !== '',
                'meta_description_present_or_derivable' => trim((string) ($content->seo_meta_description ?: $content->public_blog_excerpt)) !== '',
                'primary_keyword_present' => $primaryKeyword !== '',
                'canonical_risk_low' => $this->canonicalRiskLow($content),
            ],
            'schema' => [
                'recommended_schema_types_present' => $schemaRecommendations !== [],
                'faq_schema_present_when_required' => ! in_array('FAQPage', $schemaRecommendations, true) || in_array((string) $content->schema_type, ['FAQPage'], true) || in_array('FAQPage', $schemaRecommendations, true),
                'breadcrumb_schema_present_when_required' => ! in_array('BreadcrumbList', $schemaRecommendations, true) || in_array('BreadcrumbList', $schemaRecommendations, true),
            ],
            'internal_linking' => [
                'internal_linking_plan_present' => $internalLinkingPlan !== [],
                'has_parent_sibling_or_related_suggestion' => $this->hasLinkSuggestion($internalLinkingPlan),
                'no_orphan_risk_for_cluster' => $clusterItemsCount <= 1 || $this->hasLinkSuggestion($internalLinkingPlan),
            ],
            'content_quality' => [
                'review_approved' => $review?->status === ProgrammaticDraftReview::STATUS_APPROVED,
                'completeness_score_sufficient' => (float) ($review?->completeness_score ?? 0) >= 70,
                'risk_score_acceptable' => (float) ($review?->risk_score ?? 100) <= 70,
            ],
            'destination_readiness' => [
                'destination_configured' => $destination !== null || $content->client_site_id !== null,
                'content_type_publishable' => in_array((string) ($content->type?->value ?? $content->type ?? 'article'), ['article', 'seo_page', 'knowledge_base', 'press_release', 'blog_post', 'page', ''], true),
                'required_destination_metadata_present' => $destination !== null || $content->client_site_id !== null,
            ],
            'publication_risk' => [
                'duplicate_risk_acceptable' => (float) ($review?->item?->duplicate_risk_score ?? 0) < 70,
                'schema_not_missing' => $schemaRecommendations !== [],
                'links_not_missing' => $this->hasLinkSuggestion($internalLinkingPlan),
                'approved_review_present' => $review?->status === ProgrammaticDraftReview::STATUS_APPROVED,
                'destination_present' => $destination !== null || $content->client_site_id !== null,
            ],
        ];

        $scores = [
            'seo_score' => $this->score($checks['seo']),
            'schema_score' => $this->score($checks['schema']),
            'internal_linking_score' => $this->score($checks['internal_linking']),
            'destination_readiness_score' => $this->score($checks['destination_readiness']),
            'content_quality_score' => $this->score($checks['content_quality']),
            'publication_risk_score' => round(100 - $this->score($checks['publication_risk']), 2),
        ];
        $scores['readiness_score'] = $this->clamp((
            $scores['seo_score'] * 0.2
            + $scores['schema_score'] * 0.15
            + $scores['internal_linking_score'] * 0.15
            + $scores['destination_readiness_score'] * 0.2
            + $scores['content_quality_score'] * 0.3
        ) - ($scores['publication_risk_score'] * 0.15));

        [$missing, $recommendations] = $this->messages($checks, $type, $destination !== null || $content->client_site_id !== null);
        $status = $this->statusFrom($checks, $scores['readiness_score'], $content->publicationReadiness?->status);

        return DB::transaction(function () use ($content, $review, $type, $status, $scores, $checks, $missing, $recommendations, $schemaRecommendations, $internalLinkingPlan, $primaryKeyword): ProgrammaticPublicationReadiness {
            return ProgrammaticPublicationReadiness::query()->updateOrCreate(
                ['content_id' => (string) $content->id],
                [
                    'workspace_id' => (string) $content->workspace_id,
                    'growth_program_id' => $review?->growth_program_id ?: data_get($content->aeo_breakdown, 'programmatic_metadata.growth_program_id'),
                    'programmatic_draft_review_id' => $review?->id,
                    'programmatic_draft_request_id' => $review?->programmatic_draft_request_id,
                    'programmatic_cluster_id' => $review?->programmatic_cluster_id,
                    'programmatic_cluster_item_id' => $review?->programmatic_cluster_item_id,
                    'growth_asset_type' => $type?->value,
                    'status' => $status,
                    'readiness_score' => $scores['readiness_score'],
                    'seo_score' => $scores['seo_score'],
                    'schema_score' => $scores['schema_score'],
                    'internal_linking_score' => $scores['internal_linking_score'],
                    'publication_risk_score' => $scores['publication_risk_score'],
                    'destination_readiness_score' => $scores['destination_readiness_score'],
                    'checks' => $checks,
                    'missing_requirements' => $missing,
                    'recommendations' => $recommendations,
                    'metadata' => [
                        'source' => 'programmatic_publication_readiness',
                        'schema_recommendations' => $schemaRecommendations,
                        'internal_linking_plan' => $internalLinkingPlan,
                        'primary_keyword' => $primaryKeyword,
                    ],
                ]
            )->refresh();
        });
    }

    public function checkCluster(ProgrammaticCluster $cluster): int
    {
        return $this->reviewsForCluster($cluster)
            ->map(fn (ProgrammaticDraftReview $review): ?Content => $review->linkedContent())
            ->filter()
            ->unique('id')
            ->map(fn (Content $content): ProgrammaticPublicationReadiness => $this->checkContent($content))
            ->count();
    }

    /**
     * @param Collection<int,Content> $contents
     */
    public function checkContents(Collection $contents): int
    {
        return $contents
            ->filter(fn ($content): bool => $content instanceof Content)
            ->unique('id')
            ->map(fn (Content $content): ProgrammaticPublicationReadiness => $this->checkContent($content))
            ->count();
    }

    public function existingReadinessFor(Content $content): ?ProgrammaticPublicationReadiness
    {
        return ProgrammaticPublicationReadiness::query()
            ->where('content_id', $content->id)
            ->first();
    }

    /**
     * @return Collection<int,ProgrammaticDraftReview>
     */
    private function reviewsForCluster(ProgrammaticCluster $cluster): Collection
    {
        return ProgrammaticDraftReview::query()
            ->where('programmatic_cluster_id', $cluster->id)
            ->with(['draft', 'request.blueprint', 'cluster.items', 'item'])
            ->get();
    }

    private function reviewForContent(Content $content): ?ProgrammaticDraftReview
    {
        $reviewId = (string) data_get($content->aeo_breakdown, 'programmatic_metadata.programmatic_draft_review_id', '');
        if ($reviewId !== '') {
            $review = ProgrammaticDraftReview::query()->whereKey($reviewId)->first();
            if ($review) {
                return $review;
            }
        }

        return ProgrammaticDraftReview::query()
            ->where('metadata->converted_content_id', (string) $content->id)
            ->orWhereHas('draft', fn ($query) => $query->where('content_id', $content->id))
            ->first();
    }

    private function growthAssetType(Content $content, ?ProgrammaticDraftReview $review): ?GrowthAssetType
    {
        $value = $review?->growth_asset_type instanceof GrowthAssetType
            ? $review->growth_asset_type->value
            : ($review?->growth_asset_type ?: data_get($content->aeo_breakdown, 'growth_asset_type'));

        return GrowthAssetType::tryFrom((string) $value);
    }

    /**
     * @return array<int,string>
     */
    private function schemaRecommendations(Content $content, ?ProgrammaticDraftReview $review): array
    {
        return array_values(array_unique(array_filter(array_map('strval', Arr::flatten([
            data_get($content->aeo_breakdown, 'schema_recommendations', []),
            data_get($content->aeo_breakdown, 'programmatic_metadata.schema_recommendations', []),
            data_get($review?->request?->metadata, 'programmatic_context.schema_recommendations', []),
            $review?->request?->blueprint?->schema_recommendations ?? [],
            $content->schema_type,
        ])))));
    }

    /**
     * @return array<string,mixed>
     */
    private function internalLinkingPlan(Content $content, ?ProgrammaticDraftReview $review): array
    {
        return array_filter([
            'content' => $content->internal_links_meta,
            'programmatic' => data_get($content->aeo_breakdown, 'programmatic_metadata.internal_linking_plan'),
            'request' => data_get($review?->request?->metadata, 'programmatic_context.internal_linking_plan'),
            'blueprint' => $review?->request?->blueprint?->internal_linking_plan,
        ]);
    }

    private function destinationFor(Content $content): ?ContentDestination
    {
        if ($content->contentDestination instanceof ContentDestination && ($content->contentDestination->status?->value ?? $content->contentDestination->status) === ContentDestinationStatus::ACTIVE->value) {
            return $content->contentDestination;
        }

        return ContentDestination::query()
            ->where('workspace_id', $content->workspace_id)
            ->where('status', ContentDestinationStatus::ACTIVE->value)
            ->first();
    }

    private function canonicalRiskLow(Content $content): bool
    {
        $canonical = trim((string) $content->seo_canonical);

        return $canonical === '' || str_starts_with($canonical, 'http://') || str_starts_with($canonical, 'https://');
    }

    /**
     * @param array<string,mixed> $plan
     */
    private function hasLinkSuggestion(array $plan): bool
    {
        return collect(Arr::flatten($plan))
            ->filter(fn ($value): bool => trim((string) $value) !== '')
            ->isNotEmpty();
    }

    /**
     * @param array<string,bool> $checks
     */
    private function score(array $checks): float
    {
        if ($checks === []) {
            return 0.0;
        }

        return round((collect($checks)->filter()->count() / count($checks)) * 100, 2);
    }

    private function clamp(float $score): float
    {
        return round(max(0, min(100, $score)), 2);
    }

    /**
     * @param array<string,array<string,bool>> $checks
     * @return array{0:array<int,string>,1:array<int,string>}
     */
    private function messages(array $checks, ?GrowthAssetType $type, bool $destinationPresent): array
    {
        $missing = [];
        foreach ($checks as $group => $items) {
            foreach ($items as $key => $passed) {
                if (! $passed) {
                    $missing[] = str($group.'.'.$key)->replace('_', ' ')->headline()->toString();
                }
            }
        }

        $recommendations = [];
        if (! $destinationPresent) {
            $recommendations[] = 'Configure an active publishing destination before scheduling this content.';
        }
        if (! $checks['internal_linking']['internal_linking_plan_present']) {
            $recommendations[] = 'Add parent, sibling or related internal link suggestions.';
        }
        if (! $checks['schema']['recommended_schema_types_present']) {
            $recommendations[] = 'Add schema recommendations for the '.($type?->label() ?? 'programmatic asset').'.';
        }
        if (! $checks['content_quality']['review_approved']) {
            $recommendations[] = 'Approve the programmatic draft review before publication preparation.';
        }

        return [$missing, $recommendations];
    }

    /**
     * @param array<string,array<string,bool>> $checks
     */
    private function statusFrom(array $checks, float $readinessScore, ?string $currentStatus): string
    {
        if (! $checks['content_quality']['review_approved'] || ! $checks['destination_readiness']['destination_configured']) {
            return ProgrammaticPublicationReadiness::STATUS_BLOCKED;
        }

        if ($currentStatus === ProgrammaticPublicationReadiness::STATUS_APPROVED && $readinessScore >= 50) {
            return ProgrammaticPublicationReadiness::STATUS_APPROVED;
        }

        if ($readinessScore >= 80 && $checks['publication_risk']['duplicate_risk_acceptable']) {
            return ProgrammaticPublicationReadiness::STATUS_READY;
        }

        return $readinessScore >= 50
            ? ProgrammaticPublicationReadiness::STATUS_NEEDS_WORK
            : ProgrammaticPublicationReadiness::STATUS_BLOCKED;
    }
}
