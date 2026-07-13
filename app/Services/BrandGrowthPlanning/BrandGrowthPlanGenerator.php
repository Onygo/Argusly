<?php

namespace App\Services\BrandGrowthPlanning;

use App\Enums\BrandGrowthAudienceProposalType;
use App\Enums\BrandGrowthAudienceSourceType;
use App\Enums\BrandGrowthFindingType;
use App\Enums\BrandGrowthPlanReviewState;
use App\Enums\BrandGrowthPlanStatus;
use App\Models\BrandGrowthAudienceProposal;
use App\Models\BrandGrowthPlan;
use App\Models\BrandGrowthPlanFinding;
use App\Models\ClientSite;
use App\Models\User;
use App\Models\Workspace;
use App\Services\BrandGrowthPlanning\Analyzers\AudienceIntelligenceAnalyzer;
use App\Services\BrandGrowthPlanning\Analyzers\BrandGrowthAnalyzer;
use App\Services\BrandGrowthPlanning\Analyzers\CompetitorIntelligenceAnalyzer;
use App\Services\BrandGrowthPlanning\Analyzers\ContentAuthorityAnalyzer;
use App\Services\BrandGrowthPlanning\Analyzers\VisibilityAnalyzer;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BrandGrowthPlanGenerator
{
    /** @var array<string, BrandGrowthAnalyzer> */
    private readonly array $analyzers;

    public function __construct(
        private readonly BrandGrowthPlanningContextCollector $contextCollector,
        AudienceIntelligenceAnalyzer $audience,
        ContentAuthorityAnalyzer $contentAuthority,
        CompetitorIntelligenceAnalyzer $competitor,
        VisibilityAnalyzer $visibility,
    ) {
        $this->analyzers = [
            'audience_intelligence' => $audience,
            'content_authority' => $contentAuthority,
            'competitor_intelligence' => $competitor,
            'visibility' => $visibility,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function generate(Workspace $workspace, ?User $user = null, array $options = []): BrandGrowthPlan
    {
        $workspace->loadMissing('clientSites');
        $context = $this->contextCollector->collect($workspace);
        $results = $this->runAnalyzers($context);
        $findings = $this->normalizeFindings(
            collect($results)->flatMap(fn (BrandGrowthAnalyzerResult $result): array => $result->findings)->values()->all(),
            $context
        );
        $audienceProposals = $this->normalizeAudienceProposals(
            collect($results)->flatMap(fn (BrandGrowthAnalyzerResult $result): array => $result->audienceProposals)->values()->all(),
            $context
        );
        $sections = $this->planSections($context, $findings, $audienceProposals, $results);
        $clientSiteId = $this->clientSiteId($workspace, $options['client_site_id'] ?? null);

        return DB::transaction(function () use ($workspace, $user, $options, $context, $results, $findings, $audienceProposals, $sections, $clientSiteId): BrandGrowthPlan {
            $latest = BrandGrowthPlan::query()
                ->where('workspace_id', $workspace->id)
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();

            BrandGrowthPlan::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('status', [BrandGrowthPlanStatus::DRAFT->value, BrandGrowthPlanStatus::REVIEWING->value])
                ->update(['status' => BrandGrowthPlanStatus::SUPERSEDED->value]);

            $plan = BrandGrowthPlan::query()->create([
                'organization_id' => $workspace->organization_id,
                'workspace_id' => (string) $workspace->id,
                'client_site_id' => $clientSiteId,
                'supersedes_plan_id' => $latest?->id,
                'status' => BrandGrowthPlanStatus::DRAFT->value,
                'version' => ((int) ($latest?->version ?? 0)) + 1,
                'planning_horizon' => (string) ($options['planning_horizon'] ?? 'next_90_days'),
                'business_objective' => $this->nullableString($options['business_objective'] ?? null)
                    ?: $this->nullableString(data_get($context, 'company_profile.value_proposition')),
                'brand_objective' => $this->nullableString($options['brand_objective'] ?? null)
                    ?: 'Make the brand more visible, credible, relevant, and memorable among the right audiences.',
                'generated_at' => now(),
                'source_data_cutoff_at' => data_get($context, 'source_data_cutoff_at') ?: now(),
                'confidence_score' => $this->averageConfidence($results),
                'confidence_summary' => $this->confidenceSummary($results),
                'assumptions' => $this->uniqueStrings(collect($results)->flatMap(fn (BrandGrowthAnalyzerResult $result): array => $result->assumptions)->all()),
                'missing_information' => $this->uniqueStrings(array_merge(
                    Arr::wrap(data_get($context, 'missing_information', [])),
                    collect($results)->flatMap(fn (BrandGrowthAnalyzerResult $result): array => $result->missingData)->all(),
                )),
                'context_snapshot' => $context,
                'recommended_primary_audiences' => $sections['recommended_primary_audiences'],
                'recommended_secondary_audiences' => $sections['recommended_secondary_audiences'],
                'priority_industries' => $sections['priority_industries'],
                'buying_committee_roles' => $sections['buying_committee_roles'],
                'positioning_observations' => $sections['positioning_observations'],
                'messaging_priorities' => $sections['messaging_priorities'],
                'authority_priorities' => $sections['authority_priorities'],
                'evidence_priorities' => $sections['evidence_priorities'],
                'content_priorities' => $sections['content_priorities'],
                'campaign_themes' => $sections['campaign_themes'],
                'channel_recommendations' => $sections['channel_recommendations'],
                'kpi_recommendations' => $sections['kpi_recommendations'],
                'top_prioritized_actions' => $sections['top_prioritized_actions'],
                'generated_by_metadata' => [
                    'schema_version' => 'brand_growth_planning.plan_generator.v1',
                    'pipeline' => array_keys($this->analyzers),
                    'created_by_user_id' => $user?->id,
                    'deterministic' => true,
                ],
                'created_by' => $user?->id,
            ]);

            foreach ($findings as $finding) {
                BrandGrowthPlanFinding::query()->create(array_merge($finding, [
                    'organization_id' => $workspace->organization_id,
                    'workspace_id' => (string) $workspace->id,
                    'brand_growth_plan_id' => (string) $plan->id,
                ]));
            }

            foreach ($audienceProposals as $proposal) {
                BrandGrowthAudienceProposal::query()->create(array_merge($proposal, [
                    'organization_id' => $workspace->organization_id,
                    'workspace_id' => (string) $workspace->id,
                    'brand_growth_plan_id' => (string) $plan->id,
                ]));
            }

            return $plan->load(['findings', 'audienceProposals']);
        });
    }

    /**
     * @return array<string, BrandGrowthAnalyzerResult>
     */
    private function runAnalyzers(array $context): array
    {
        $results = [];

        foreach ($this->analyzers as $key => $analyzer) {
            $results[$key] = $analyzer->analyze($context);
        }

        return $results;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeFindings(array $items, array $context): array
    {
        return collect($items)
            ->map(function (array $item) use ($context): ?array {
                $type = BrandGrowthFindingType::tryFrom((string) ($item['type'] ?? ''))
                    ?: BrandGrowthFindingType::CONTENT_GAP;
                $title = $this->limit($this->nullableString($item['title'] ?? null) ?: str_replace('_', ' ', $type->value), 220);

                if ($title === '') {
                    return null;
                }

                $sourceReferences = $this->validatedSourceReferences((array) ($item['source_references'] ?? []), $context);
                $contentId = $this->validReferenceId($item['content_id'] ?? null, 'content_ids', $context)
                    ?: Arr::first($sourceReferences['content_ids'] ?? []);
                $monitoredPageId = $this->validReferenceId($item['monitored_page_id'] ?? null, 'monitored_page_ids', $context)
                    ?: Arr::first($sourceReferences['monitored_page_ids'] ?? []);
                $competitorId = $this->validReferenceId($item['site_competitor_id'] ?? null, 'site_competitor_ids', $context)
                    ?: Arr::first($sourceReferences['site_competitor_ids'] ?? []);

                return [
                    'content_id' => $contentId,
                    'monitored_page_id' => $monitoredPageId,
                    'site_competitor_id' => $competitorId,
                    'type' => $type->value,
                    'status' => BrandGrowthPlanFinding::STATUS_ACTIVE,
                    'review_state' => BrandGrowthPlanReviewState::PENDING->value,
                    'title' => $title,
                    'description' => $this->nullableString($item['description'] ?? null),
                    'rationale' => $this->nullableString($item['rationale'] ?? null),
                    'impact_score' => $this->clampScore($item['impact_score'] ?? 0),
                    'urgency_score' => $this->clampScore($item['urgency_score'] ?? 0),
                    'confidence_score' => $this->clampScore($item['confidence_score'] ?? 0),
                    'affected_audience' => $this->limit($this->nullableString($item['affected_audience'] ?? null), 220),
                    'affected_industry' => $this->limit($this->nullableString($item['affected_industry'] ?? null), 220),
                    'affected_funnel_stage' => $this->limit($this->nullableString($item['affected_funnel_stage'] ?? null), 80),
                    'recommended_action' => $this->nullableString($item['recommended_action'] ?? null),
                    'source_references' => $sourceReferences,
                    'source_summary' => (array) ($item['source_summary'] ?? []),
                    'metadata_json' => (array) ($item['metadata_json'] ?? []),
                    'dedupe_hash' => $this->dedupeHash([
                        $type->value,
                        $title,
                        $item['affected_audience'] ?? '',
                        $item['affected_industry'] ?? '',
                        $item['affected_funnel_stage'] ?? '',
                    ]),
                ];
            })
            ->filter()
            ->unique('dedupe_hash')
            ->sortByDesc(fn (array $finding): float => ((float) $finding['impact_score'] * 0.45) + ((float) $finding['urgency_score'] * 0.35) + ((float) $finding['confidence_score'] * 0.2))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeAudienceProposals(array $items, array $context): array
    {
        return collect($items)
            ->map(function (array $item) use ($context): ?array {
                $name = $this->limit($this->nullableString($item['name'] ?? null), 220);

                if ($name === '') {
                    return null;
                }

                $proposalType = BrandGrowthAudienceProposalType::tryFrom((string) ($item['proposal_type'] ?? ''))
                    ?: BrandGrowthAudienceProposalType::AUDIENCE;
                $sourceType = BrandGrowthAudienceSourceType::tryFrom((string) ($item['source_type'] ?? ''))
                    ?: BrandGrowthAudienceSourceType::INFERRED;

                return [
                    'proposal_type' => $proposalType->value,
                    'source_type' => $sourceType->value,
                    'review_state' => BrandGrowthPlanReviewState::PENDING->value,
                    'name' => $name,
                    'role' => $this->limit($this->nullableString($item['role'] ?? null), 220),
                    'seniority' => $this->limit($this->nullableString($item['seniority'] ?? null), 120),
                    'department' => $this->limit($this->nullableString($item['department'] ?? null), 160),
                    'industry' => $this->limit($this->nullableString($item['industry'] ?? data_get($context, 'company_profile.industry')), 220),
                    'company_size' => $this->limit($this->nullableString($item['company_size'] ?? null), 120),
                    'responsibilities' => $this->stringList($item['responsibilities'] ?? []),
                    'goals' => $this->stringList($item['goals'] ?? []),
                    'pain_points' => $this->stringList($item['pain_points'] ?? []),
                    'objections' => $this->stringList($item['objections'] ?? []),
                    'buying_triggers' => $this->stringList($item['buying_triggers'] ?? []),
                    'kpis' => $this->stringList($item['kpis'] ?? []),
                    'preferred_content' => $this->stringList($item['preferred_content'] ?? []),
                    'buying_stage_relevance' => $this->stringList($item['buying_stage_relevance'] ?? []),
                    'buying_committee_role' => $this->limit($this->nullableString($item['buying_committee_role'] ?? $item['role'] ?? null), 120),
                    'confidence_score' => $this->clampScore($item['confidence_score'] ?? 0),
                    'source_references' => $this->validatedSourceReferences((array) ($item['source_references'] ?? []), $context),
                    'metadata_json' => (array) ($item['metadata_json'] ?? []),
                    'dedupe_hash' => $this->dedupeHash([
                        $proposalType->value,
                        $sourceType->value,
                        $name,
                        $item['role'] ?? '',
                        $item['industry'] ?? '',
                    ]),
                ];
            })
            ->filter()
            ->unique('dedupe_hash')
            ->sortByDesc('confidence_score')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     * @param  array<int, array<string, mixed>>  $audienceProposals
     * @param  array<string, BrandGrowthAnalyzerResult>  $results
     * @return array<string, array<int, mixed>>
     */
    private function planSections(array $context, array $findings, array $audienceProposals, array $results): array
    {
        $audienceNames = collect($audienceProposals)->pluck('name')->filter()->unique()->values();
        $buyerRoles = collect($audienceProposals)
            ->filter(fn (array $proposal): bool => ($proposal['proposal_type'] ?? '') === BrandGrowthAudienceProposalType::BUYING_COMMITTEE_ROLE->value)
            ->pluck('role')
            ->merge(Arr::wrap(data_get($context, 'brand_intelligence.audience.buyer_roles', [])))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $recommendedActions = collect($results)
            ->flatMap(fn (BrandGrowthAnalyzerResult $result): array => $result->recommendedActions)
            ->filter()
            ->values()
            ->all();

        return [
            'recommended_primary_audiences' => $audienceNames->take(5)->values()->all(),
            'recommended_secondary_audiences' => $audienceNames->slice(5)->take(5)->values()->all(),
            'priority_industries' => $this->uniqueStrings(array_merge(
                Arr::wrap(data_get($context, 'company_profile.industry')),
                collect($audienceProposals)->pluck('industry')->all(),
                collect($findings)->pluck('affected_industry')->all(),
            )),
            'buying_committee_roles' => $this->uniqueStrings($buyerRoles),
            'positioning_observations' => $this->findingSummaries($findings, [
                BrandGrowthFindingType::POSITIONING_GAP,
                BrandGrowthFindingType::COMPETITOR_THREAT,
                BrandGrowthFindingType::COMPETITOR_OPPORTUNITY,
            ]),
            'messaging_priorities' => $this->findingSummaries($findings, [
                BrandGrowthFindingType::MESSAGING_GAP,
                BrandGrowthFindingType::AUDIENCE_OPPORTUNITY,
                BrandGrowthFindingType::PERSONA_GAP,
            ]),
            'authority_priorities' => $this->findingSummaries($findings, [
                BrandGrowthFindingType::AUTHORITY_GAP,
                BrandGrowthFindingType::AI_VISIBILITY_GAP,
            ]),
            'evidence_priorities' => $this->findingSummaries($findings, [
                BrandGrowthFindingType::EVIDENCE_GAP,
                BrandGrowthFindingType::MEASUREMENT_GAP,
            ]),
            'content_priorities' => $this->findingSummaries($findings, [
                BrandGrowthFindingType::CONTENT_GAP,
                BrandGrowthFindingType::INDUSTRY_GAP,
                BrandGrowthFindingType::SERP_OPPORTUNITY,
            ]),
            'campaign_themes' => $this->findingSummaries($findings, [
                BrandGrowthFindingType::CAMPAIGN_OPPORTUNITY,
                BrandGrowthFindingType::CHANNEL_OPPORTUNITY,
                BrandGrowthFindingType::COMPETITOR_OPPORTUNITY,
                BrandGrowthFindingType::AI_VISIBILITY_GAP,
            ]),
            'channel_recommendations' => $recommendedActions,
            'kpi_recommendations' => [
                'Approved strategic findings promoted to Opportunities',
                'Approved inferred audiences converted into governed personas',
                'Evidence-led assets created for priority decision stages',
                'AI visibility queries mapped to authority areas and proof assets',
            ],
            'top_prioritized_actions' => collect($findings)
                ->sortByDesc(fn (array $finding): float => ((float) $finding['impact_score'] * 0.45) + ((float) $finding['urgency_score'] * 0.35) + ((float) $finding['confidence_score'] * 0.2))
                ->take(8)
                ->map(fn (array $finding): array => [
                    'title' => $finding['title'],
                    'type' => $finding['type'],
                    'action' => $finding['recommended_action'],
                    'impact_score' => $finding['impact_score'],
                    'urgency_score' => $finding['urgency_score'],
                    'confidence_score' => $finding['confidence_score'],
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     * @param  array<int, BrandGrowthFindingType>  $types
     * @return array<int, array<string, mixed>>
     */
    private function findingSummaries(array $findings, array $types): array
    {
        $values = collect($types)->map(fn (BrandGrowthFindingType $type): string => $type->value)->all();

        return collect($findings)
            ->filter(fn (array $finding): bool => in_array((string) ($finding['type'] ?? ''), $values, true))
            ->take(6)
            ->map(fn (array $finding): array => [
                'title' => $finding['title'],
                'summary' => $finding['description'] ?: $finding['rationale'],
                'recommended_action' => $finding['recommended_action'],
                'confidence_score' => $finding['confidence_score'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, BrandGrowthAnalyzerResult>  $results
     * @return array<string, mixed>
     */
    private function confidenceSummary(array $results): array
    {
        return [
            'average_confidence' => $this->averageConfidence($results),
            'analyzers' => collect($results)
                ->map(fn (BrandGrowthAnalyzerResult $result, string $key): array => [
                    'key' => $key,
                    'summary' => $result->summary,
                    'confidence' => $this->clampScore($result->confidence),
                    'sources_used' => $result->sourcesUsed,
                    'sources_not_available' => $result->sourcesNotAvailable,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, BrandGrowthAnalyzerResult>  $results
     */
    private function averageConfidence(array $results): float
    {
        if ($results === []) {
            return 0.0;
        }

        return round((float) collect($results)->avg(fn (BrandGrowthAnalyzerResult $result): float => $this->clampScore($result->confidence)), 2);
    }

    /**
     * @param  array<string, mixed>  $references
     * @return array<string, array<int, string>>
     */
    private function validatedSourceReferences(array $references, array $context): array
    {
        $index = (array) data_get($context, 'source_reference_index', []);

        return collect($references)
            ->mapWithKeys(function (mixed $ids, string $key) use ($index): array {
                if (! array_key_exists($key, $index)) {
                    return [];
                }

                $validIds = collect(Arr::wrap($index[$key]))
                    ->map(fn (mixed $id): string => (string) $id)
                    ->filter()
                    ->values();
                $values = collect(Arr::wrap($ids))
                    ->map(fn (mixed $id): string => (string) $id)
                    ->filter()
                    ->intersect($validIds)
                    ->values()
                    ->all();

                return $values === [] ? [] : [$key => $values];
            })
            ->all();
    }

    private function validReferenceId(mixed $id, string $key, array $context): ?string
    {
        $id = $this->nullableString($id);

        if (! $id) {
            return null;
        }

        $validIds = collect(data_get($context, 'source_reference_index.'.$key, []))
            ->map(fn (mixed $value): string => (string) $value)
            ->all();

        return in_array($id, $validIds, true) ? $id : null;
    }

    private function clientSiteId(Workspace $workspace, mixed $clientSiteId): ?string
    {
        $clientSiteId = $this->nullableString($clientSiteId);

        if (! $clientSiteId) {
            return null;
        }

        return ClientSite::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($clientSiteId)
            ->value('id');
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private function stringList(mixed $values): array
    {
        return $this->uniqueStrings(Arr::wrap($values));
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private function uniqueStrings(array $values): array
    {
        return collect($values)
            ->flatten()
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique(fn (string $value): string => mb_strtolower($value))
            ->values()
            ->all();
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function limit(?string $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        return Str::limit($value, $limit, '');
    }

    private function clampScore(mixed $score): float
    {
        return round(max(0, min(100, (float) $score)), 2);
    }

    /**
     * @param  array<int, mixed>  $parts
     */
    private function dedupeHash(array $parts): string
    {
        return hash('sha256', collect($parts)
            ->map(fn (mixed $part): string => mb_strtolower(trim((string) $part)))
            ->implode('|'));
    }
}
