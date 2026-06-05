<?php

namespace App\Services\LinkIntelligence;

use App\Contracts\LinkIntelligence\AnchorTextService;
use App\Contracts\LinkIntelligence\LinkRelevanceService;
use App\Contracts\LinkIntelligence\LinkSuggestionService;
use App\Models\CrossLinkPermission;
use App\Models\Draft;
use App\Models\LinkProfile;
use App\Models\LinkSuggestion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DefaultLinkSuggestionService implements LinkSuggestionService
{
    public function __construct(
        private readonly LinkRelevanceService $linkRelevanceService,
        private readonly AnchorTextService $anchorTextService,
    ) {}

    public function generateSuggestions(Draft $source): Collection
    {
        $source->loadMissing('clientSite.workspace');

        if (! $source->clientSite?->workspace_id) {
            return collect();
        }

        $profile = LinkProfile::query()->firstOrCreate([
            'workspace_id' => $source->clientSite->workspace_id,
        ]);

        if (! $profile->default_internal_linking_enabled) {
            return collect();
        }

        $candidates = $this->collectCandidates($source, $profile);

        return DB::transaction(function () use ($source, $profile, $candidates) {
            LinkSuggestion::query()
                ->where('source_article_id', $source->id)
                ->whereIn('status', ['draft', 'suggested'])
                ->delete();

            $created = collect();
            $currentCount = $this->currentOutboundCount($source->id);

            foreach ($candidates as $candidate) {
                if ($currentCount >= $profile->max_outbound_links_per_article) {
                    break;
                }

                $score = $this->linkRelevanceService->scoreCandidate($source, $candidate);
                if (! $score->isEligible) {
                    continue;
                }

                if ($this->hasCompetitorConflict($source, $candidate)) {
                    continue;
                }

                if ($source->clientSite->workspace_id !== $candidate->clientSite?->workspace_id) {
                    if ($this->reachedMonthlyCrossDomainLimit($profile, $source->clientSite->workspace_id)) {
                        continue;
                    }

                    if ($this->hasRecentReciprocalLink(
                        $source->clientSite->workspace_id,
                        (string) $candidate->clientSite?->workspace_id,
                    )) {
                        continue;
                    }
                }

                $suggestion = LinkSuggestion::query()->create([
                    'source_article_id' => $source->id,
                    'target_article_id' => $candidate->id,
                    'source_workspace_id' => $source->clientSite->workspace_id,
                    'target_workspace_id' => (string) $candidate->clientSite?->workspace_id,
                    'source_client_site_id' => $source->client_site_id,
                    'target_client_site_id' => $candidate->client_site_id,
                    'similarity_score' => $score->similarityScore,
                    'shared_entities' => $score->sharedEntities,
                    'intent_match_score' => $score->intentMatchScore,
                    'audience_overlap_score' => $score->audienceOverlapScore,
                    'suggested_anchor_variants' => $this->anchorTextService->generateAnchorVariants($source, $candidate),
                    'suggested_placement' => $score->similarityScore >= 0.8 ? 'inline' : 'footnote',
                    'status' => 'suggested',
                ]);

                $created->push($suggestion);
                $currentCount++;
            }

            return $created;
        });
    }

    /**
     * Debug-only: explain why each candidate is accepted or rejected.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function debugCandidates(Draft $source): Collection
    {
        $source->loadMissing('clientSite.workspace');
        if (! $source->clientSite?->workspace_id) {
            return collect();
        }

        $profile = LinkProfile::query()->firstOrCreate([
            'workspace_id' => $source->clientSite->workspace_id,
        ]);

        $candidates = $this->collectCandidates($source, $profile);
        $currentCount = $this->currentOutboundCount($source->id);

        return $candidates->map(function (Draft $candidate) use ($source, $profile, &$currentCount): array {
            $score = $this->linkRelevanceService->scoreCandidate($source, $candidate);
            $reasons = [];

            if (! $score->isEligible) {
                $reasons = array_merge($reasons, $score->reasons);
            }

            if ($this->hasCompetitorConflict($source, $candidate)) {
                $reasons[] = 'Blocked by competitor conflict rule.';
            }

            if ($currentCount >= $profile->max_outbound_links_per_article) {
                $reasons[] = 'Blocked by max outbound links per article.';
            }

            if ($source->clientSite->workspace_id !== $candidate->clientSite?->workspace_id) {
                if ($this->reachedMonthlyCrossDomainLimit($profile, $source->clientSite->workspace_id)) {
                    $reasons[] = 'Blocked by max cross-domain links this month.';
                }

                if ($this->hasRecentReciprocalLink(
                    $source->clientSite->workspace_id,
                    (string) $candidate->clientSite?->workspace_id,
                )) {
                    $reasons[] = 'Blocked by 30-day reciprocal rule.';
                }
            }

            $accepted = $reasons === [];
            if ($accepted) {
                $currentCount++;
                $reasons[] = 'Eligible and would be suggested.';
            }

            return [
                'target_article_id' => (string) $candidate->id,
                'target_title' => (string) $candidate->title,
                'target_site_url' => (string) ($candidate->clientSite?->site_url ?? ''),
                'target_workspace_id' => (string) ($candidate->clientSite?->workspace_id ?? ''),
                'accepted' => $accepted,
                'reasons' => array_values(array_unique($reasons)),
                'similarity_score' => $score->similarityScore,
                'intent_match_score' => $score->intentMatchScore,
                'audience_overlap_score' => $score->audienceOverlapScore,
                'shared_entities' => $score->sharedEntities,
            ];
        })->values();
    }

    /**
     * Debug-only: summarize candidate pool filtering stages.
     *
     * @return array<string, mixed>
     */
    public function debugPoolSummary(Draft $source): array
    {
        $source->loadMissing('clientSite.workspace');

        if (! $source->clientSite?->workspace_id) {
            return [
                'source_id' => (string) $source->id,
                'has_workspace' => false,
            ];
        }

        $eligibleStatuses = $this->eligibleCandidateStatuses();
        $workspaceId = (string) $source->clientSite->workspace_id;
        $siteId = (string) $source->client_site_id;

        $internalBase = Draft::query()
            ->where('client_site_id', $siteId)
            ->where('id', '!=', $source->id);

        $internalStatusCounts = (clone $internalBase)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->toArray();

        $internalTotal = (clone $internalBase)->count();
        $internalWithHtml = (clone $internalBase)->whereNotNull('content_html')->where('content_html', '!=', '')->count();
        $internalWithEligibleStatus = (clone $internalBase)->whereIn('status', $eligibleStatuses)->count();
        $internalEligible = (clone $internalBase)
            ->whereNotNull('content_html')
            ->where('content_html', '!=', '')
            ->whereIn('status', $eligibleStatuses)
            ->count();

        $profile = LinkProfile::query()->firstOrCreate(['workspace_id' => $workspaceId]);

        $approvedWorkspaceIds = [];
        if ($profile->external_suggestions_enabled) {
            $approvedWorkspaceIds = CrossLinkPermission::query()
                ->where('from_workspace_id', $workspaceId)
                ->where('status', 'approved')
                ->pluck('to_workspace_id')
                ->all();
        }

        $externalBase = Draft::query()
            ->where('id', '!=', $source->id)
            ->whereHas('clientSite', function ($query) use ($approvedWorkspaceIds): void {
                $query->whereIn('workspace_id', $approvedWorkspaceIds);
            });

        $externalEligible = $approvedWorkspaceIds === []
            ? 0
            : (clone $externalBase)
                ->whereNotNull('content_html')
                ->where('content_html', '!=', '')
                ->whereIn('status', $eligibleStatuses)
                ->count();

        return [
            'source_id' => (string) $source->id,
            'site_id' => $siteId,
            'workspace_id' => $workspaceId,
            'eligible_statuses' => $eligibleStatuses,
            'profile' => [
                'internal_enabled' => (bool) $profile->default_internal_linking_enabled,
                'external_enabled' => (bool) $profile->external_suggestions_enabled,
                'max_outbound_links_per_article' => (int) $profile->max_outbound_links_per_article,
            ],
            'internal' => [
                'total_other_drafts_same_site' => $internalTotal,
                'status_counts' => $internalStatusCounts,
                'with_non_empty_html' => $internalWithHtml,
                'with_eligible_status' => $internalWithEligibleStatus,
                'eligible_after_filters' => $internalEligible,
            ],
            'external' => [
                'approved_workspace_count' => count($approvedWorkspaceIds),
                'approved_workspace_ids' => $approvedWorkspaceIds,
                'eligible_after_filters' => $externalEligible,
            ],
        ];
    }

    /**
     * @return Collection<int, Draft>
     */
    private function collectCandidates(Draft $source, LinkProfile $profile): Collection
    {
        $eligibleStatuses = $this->eligibleCandidateStatuses();

        $internal = Draft::query()
            ->with('clientSite.workspace')
            ->where('client_site_id', $source->client_site_id)
            ->where('id', '!=', $source->id)
            ->whereNotNull('content_html')
            ->where('content_html', '!=', '')
            ->whereIn('status', $eligibleStatuses)
            ->limit(150)
            ->get();

        if (! $profile->external_suggestions_enabled) {
            return $internal;
        }

        $approvedWorkspaceIds = CrossLinkPermission::query()
            ->where('from_workspace_id', $source->clientSite->workspace_id)
            ->where('status', 'approved')
            ->pluck('to_workspace_id')
            ->all();

        if ($approvedWorkspaceIds === []) {
            return $internal;
        }

        $external = Draft::query()
            ->with('clientSite.workspace')
            ->whereHas('clientSite', function ($query) use ($approvedWorkspaceIds): void {
                $query->whereIn('workspace_id', $approvedWorkspaceIds);
            })
            ->where('id', '!=', $source->id)
            ->whereNotNull('content_html')
            ->where('content_html', '!=', '')
            ->whereIn('status', $eligibleStatuses)
            ->limit(200)
            ->get();

        return $internal
            ->merge($external)
            ->unique('id')
            ->values();
    }

    private function currentOutboundCount(string $sourceArticleId): int
    {
        return LinkSuggestion::query()
            ->where('source_article_id', $sourceArticleId)
            ->whereIn('status', ['suggested', 'approved', 'applied'])
            ->count();
    }

    private function reachedMonthlyCrossDomainLimit(LinkProfile $profile, string $sourceWorkspaceId): bool
    {
        $count = LinkSuggestion::query()
            ->where('source_workspace_id', $sourceWorkspaceId)
            ->whereColumn('source_workspace_id', '!=', 'target_workspace_id')
            ->whereIn('status', ['suggested', 'approved', 'applied'])
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();

        return $count >= $profile->max_cross_domain_links_per_month;
    }

    private function hasRecentReciprocalLink(string $sourceWorkspaceId, string $targetWorkspaceId): bool
    {
        return LinkSuggestion::query()
            ->where('source_workspace_id', $targetWorkspaceId)
            ->where('target_workspace_id', $sourceWorkspaceId)
            ->where('status', 'applied')
            ->where('applied_at', '>=', now()->subDays(30))
            ->exists();
    }

    private function hasCompetitorConflict(Draft $source, Draft $target): bool
    {
        // Placeholder hook for future competitor conflict checks.
        return false;
    }

    /**
     * @return array<int, string>
     */
    private function eligibleCandidateStatuses(): array
    {
        return [
            'ready',
            'generated',
            'ready_to_deliver',
            'delivered',
            'acked',
            'published',
            'revise_requested',
        ];
    }
}
