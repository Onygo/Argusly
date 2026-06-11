<?php

namespace App\Services\Growth;

use App\Enums\GrowthAssetType;
use App\Models\Draft;
use App\Models\GrowthProgram;
use App\Models\ProgrammaticDraftRequest;
use App\Models\ProgrammaticDraftReview;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ProgrammaticDraftReviewService
{
    public function reviewRequest(ProgrammaticDraftRequest $request): ProgrammaticDraftReview
    {
        $request->loadMissing(['brief', 'blueprint', 'item', 'cluster']);

        if ($request->status !== ProgrammaticDraftRequest::STATUS_GENERATED) {
            throw new InvalidArgumentException('Only generated programmatic draft requests can be reviewed.');
        }

        $draft = $request->linkedDraft();
        if (! $draft) {
            throw new InvalidArgumentException('Generated draft request has no linked Draft.');
        }

        $scores = $this->scores($request, $draft);
        $blocking = $this->blockingIssues($request, $draft, $scores);
        $status = $blocking !== []
            ? ProgrammaticDraftReview::STATUS_BLOCKED
            : ($scores['overall_score'] >= 75 ? ProgrammaticDraftReview::STATUS_PASSED : ProgrammaticDraftReview::STATUS_NEEDS_WORK);

        return ProgrammaticDraftReview::query()->updateOrCreate(
            ['programmatic_draft_request_id' => (string) $request->id],
            [
                'workspace_id' => (string) $request->workspace_id,
                'growth_program_id' => $request->growth_program_id,
                'draft_id' => (string) $draft->id,
                'brief_id' => $request->brief_id,
                'programmatic_cluster_id' => $request->programmatic_cluster_id,
                'programmatic_cluster_item_id' => $request->programmatic_cluster_item_id,
                'growth_asset_type' => $request->growth_asset_type?->value ?? (string) $request->growth_asset_type,
                'status' => $request->review?->status === ProgrammaticDraftReview::STATUS_APPROVED ? ProgrammaticDraftReview::STATUS_APPROVED : $status,
                'overall_score' => $scores['overall_score'],
                'seo_score' => $scores['seo_score'],
                'ai_visibility_score' => $scores['ai_visibility_score'],
                'duplication_score' => $scores['duplication_score'],
                'brand_fit_score' => $scores['brand_fit_score'],
                'completeness_score' => $scores['completeness_score'],
                'schema_readiness_score' => $scores['schema_readiness_score'],
                'internal_linking_score' => $scores['internal_linking_score'],
                'risk_score' => $scores['risk_score'],
                'checks' => $this->checks($request, $draft),
                'recommendations' => $this->recommendations($scores, $blocking),
                'blocking_issues' => $blocking,
                'metadata' => [
                    'source' => 'programmatic_draft_review_service',
                    'next_recommended_action' => $this->nextAction($status, $blocking),
                    'reviewed_deterministically' => true,
                ],
            ]
        )->refresh();
    }

    public function reviewCluster(string $clusterId): int
    {
        return ProgrammaticDraftRequest::query()
            ->where('programmatic_cluster_id', $clusterId)
            ->where('status', ProgrammaticDraftRequest::STATUS_GENERATED)
            ->get()
            ->reduce(function (int $count, ProgrammaticDraftRequest $request): int {
                $this->reviewRequest($request);

                return $count + 1;
            }, 0);
    }

    public function reviewProgram(GrowthProgram $program): int
    {
        return ProgrammaticDraftRequest::query()
            ->where('growth_program_id', $program->id)
            ->where('status', ProgrammaticDraftRequest::STATUS_GENERATED)
            ->get()
            ->reduce(function (int $count, ProgrammaticDraftRequest $request): int {
                $this->reviewRequest($request);

                return $count + 1;
            }, 0);
    }

    private function scores(ProgrammaticDraftRequest $request, Draft $draft): array
    {
        $checks = $this->checks($request, $draft);
        $completeness = $this->percent($checks['completeness']);
        $seo = $this->percent($checks['seo']);
        $ai = $this->percent($checks['ai_visibility']);
        $duplication = $this->duplicationScore($request);
        $schema = $this->percent($checks['schema_readiness']);
        $linking = $this->percent($checks['internal_linking']);
        $brandFit = $this->percent($checks['brand_fit']);
        $risk = $this->riskScore($completeness, $duplication, $checks);
        $overall = round(($completeness + $seo + $ai + $duplication + $schema + $linking + $brandFit + (100 - $risk)) / 8, 2);

        return [
            'overall_score' => $overall,
            'seo_score' => $seo,
            'ai_visibility_score' => $ai,
            'duplication_score' => $duplication,
            'brand_fit_score' => $brandFit,
            'completeness_score' => $completeness,
            'schema_readiness_score' => $schema,
            'internal_linking_score' => $linking,
            'risk_score' => $risk,
        ];
    }

    private function checks(ProgrammaticDraftRequest $request, Draft $draft): array
    {
        $type = $request->growth_asset_type instanceof GrowthAssetType ? $request->growth_asset_type : GrowthAssetType::tryFrom((string) $request->growth_asset_type);
        $meta = (array) $draft->meta;
        $content = trim((string) $draft->content_html);
        $schema = (array) data_get($meta, 'schema_recommendations', []);
        $faq = (array) data_get($meta, 'faq_questions', []);

        return [
            'completeness' => [
                'title_present' => filled($draft->title),
                'content_present' => $content !== '',
                'outline_present' => count((array) data_get($meta, 'outline', [])) > 0,
                'required_sections_traceable' => count((array) data_get($meta, 'required_sections', [])) > 0,
                'cta_present' => filled(data_get($meta, 'call_to_action')),
            ],
            'seo' => [
                'primary_keyword_present' => filled(data_get($meta, 'primary_keyword')),
                'meta_title_or_title_present' => filled($draft->seo_title) || filled($draft->title),
                'slug_present' => filled($request->slug),
                'secondary_keywords_present' => count((array) data_get($meta, 'secondary_keywords', [])) > 0,
                'faq_present_when_needed' => ! in_array($type, [GrowthAssetType::FAQ_PAGE, GrowthAssetType::AI_ANSWER_PAGE], true) || count($faq) > 0,
            ],
            'ai_visibility' => [
                'direct_answer_or_not_required' => ! in_array($type, [GrowthAssetType::AI_ANSWER_PAGE, GrowthAssetType::STRUCTURED_ANSWER], true) || Str::contains(Str::lower($content.' '.json_encode($meta)), ['direct answer', 'direct antwoord', 'antwoord']),
                'faq_questions_present' => count($faq) > 0,
                'entity_context_present' => filled(data_get($meta, 'audience')) || filled(data_get($meta, 'intent')),
                'schema_recommendations_present' => count($schema) > 0,
            ],
            'schema_readiness' => [
                'schema_present' => count($schema) > 0,
                'faq_page_when_needed' => ! in_array($type, [GrowthAssetType::FAQ_PAGE, GrowthAssetType::AI_ANSWER_PAGE], true) || in_array('FAQPage', $schema, true),
                'breadcrumb_when_recommended' => ! in_array('BreadcrumbList', $schema, true) || in_array('BreadcrumbList', $schema, true),
            ],
            'internal_linking' => [
                'plan_present' => count((array) data_get($meta, 'internal_linking_plan', [])) > 0,
                'role_present' => filled(data_get($meta, 'internal_linking_plan.role')),
                'related_asset_present' => filled(data_get($meta, 'internal_linking_plan.related_variable')) || filled(data_get($meta, 'internal_linking_plan.canonical_group_key')),
            ],
            'brand_fit' => [
                'audience_present' => filled(data_get($meta, 'audience')),
                'intent_present' => filled(data_get($meta, 'intent')),
                'quality_requirements_present' => count((array) data_get($meta, 'quality_requirements', [])) > 0,
            ],
        ];
    }

    private function blockingIssues(ProgrammaticDraftRequest $request, Draft $draft, array $scores): array
    {
        $issues = [];
        if (trim((string) $draft->content_html) === '') {
            $issues[] = 'Draft body is missing.';
        }
        if ($scores['completeness_score'] < 50) {
            $issues[] = 'Completeness score is below review threshold.';
        }
        if ((float) ($request->item?->duplicate_risk_score ?? 0) >= 80) {
            $issues[] = 'Duplicate risk is high for this cluster item.';
        }
        if ($scores['schema_readiness_score'] < 50) {
            $issues[] = 'Schema readiness is incomplete.';
        }

        return $issues;
    }

    private function recommendations(array $scores, array $blocking): array
    {
        $recommendations = $blocking;
        foreach (['seo_score' => 'Improve SEO metadata and keyword coverage.', 'ai_visibility_score' => 'Add answerable headings, FAQ, and entity context.', 'internal_linking_score' => 'Complete internal linking plan.'] as $key => $message) {
            if (($scores[$key] ?? 100) < 75) {
                $recommendations[] = $message;
            }
        }

        return array_values(array_unique($recommendations));
    }

    private function nextAction(string $status, array $blocking): string
    {
        return match ($status) {
            ProgrammaticDraftReview::STATUS_PASSED => 'Approve the review when editorial checks are complete.',
            ProgrammaticDraftReview::STATUS_NEEDS_WORK => 'Resolve recommendations before approving.',
            ProgrammaticDraftReview::STATUS_BLOCKED => 'Fix blocking issues before this draft can progress.',
            default => $blocking === [] ? 'Review scores and approve when ready.' : 'Resolve blocking issues.',
        };
    }

    private function duplicationScore(ProgrammaticDraftRequest $request): float
    {
        $risk = (float) ($request->item?->duplicate_risk_score ?? 0);

        return round(max(0, min(100, 100 - $risk)), 2);
    }

    private function riskScore(float $completeness, float $duplicationScore, array $checks): float
    {
        $missingCritical = collect($checks)->flatten()->filter(fn ($passed): bool => $passed === false)->count();

        return round(max(0, min(100, (100 - $completeness) * 0.45 + (100 - $duplicationScore) * 0.35 + $missingCritical * 4)), 2);
    }

    private function percent(array $checks): float
    {
        if ($checks === []) {
            return 0.0;
        }

        return round(collect($checks)->filter()->count() / count($checks) * 100, 2);
    }
}
