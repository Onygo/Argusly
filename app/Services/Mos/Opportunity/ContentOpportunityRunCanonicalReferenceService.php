<?php

namespace App\Services\Mos\Opportunity;

use App\Models\ContentOpportunity;
use App\Models\ContentOpportunityRun;
use App\Models\Opportunity;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class ContentOpportunityRunCanonicalReferenceService
{
    /**
     * @return array<string, mixed>
     */
    public function inspect(ContentOpportunityRun $run, ?string $legacyStatus = null): array
    {
        $opportunities = $this->legacyOpportunities($run, $legacyStatus);
        $canonicalByLegacyId = $this->canonicalOpportunitiesByLegacyId($opportunities);
        $byLegacyStatus = [];
        $byCanonicalStatus = [];
        $missingLinks = [];
        $missingContext = [];
        $duplicateLinkRisks = [];
        $canonicalIds = [];
        $linked = 0;

        $opportunities->each(function (ContentOpportunity $opportunity) use (
            $canonicalByLegacyId,
            &$byLegacyStatus,
            &$byCanonicalStatus,
            &$missingLinks,
            &$missingContext,
            &$duplicateLinkRisks,
            &$canonicalIds,
            &$linked,
        ): void {
            $legacyId = (string) $opportunity->id;
            $legacyStatus = (string) $opportunity->status;
            $canonicalRows = $canonicalByLegacyId->get($legacyId, collect());
            $ids = $canonicalRows
                ->pluck('id')
                ->map(fn (mixed $id): string => (string) $id)
                ->values()
                ->all();

            if ($ids === []) {
                $missingLinks[] = $legacyId;
            } else {
                $linked++;
                array_push($canonicalIds, ...$ids);
            }

            $byLegacyStatus[$legacyStatus] ??= [
                'legacy_candidate_count' => 0,
                'linked_candidate_count' => 0,
                'unlinked_candidate_count' => 0,
                'canonical_opportunity_ids' => [],
            ];
            $byLegacyStatus[$legacyStatus]['legacy_candidate_count']++;

            if ($ids === []) {
                $byLegacyStatus[$legacyStatus]['unlinked_candidate_count']++;
            } else {
                $byLegacyStatus[$legacyStatus]['linked_candidate_count']++;
                $byLegacyStatus[$legacyStatus]['canonical_opportunity_ids'] = array_values(array_unique(array_merge(
                    $byLegacyStatus[$legacyStatus]['canonical_opportunity_ids'],
                    $ids,
                )));
            }

            $canonicalRows->each(function (Opportunity $canonical) use (&$byCanonicalStatus): void {
                $status = $canonical->status instanceof \BackedEnum
                    ? (string) $canonical->status->value
                    : (string) $canonical->status;

                $byCanonicalStatus[$status] ??= [
                    'canonical_opportunity_count' => 0,
                    'canonical_opportunity_ids' => [],
                ];
                $byCanonicalStatus[$status]['canonical_opportunity_count']++;
                $byCanonicalStatus[$status]['canonical_opportunity_ids'][] = (string) $canonical->id;
                $byCanonicalStatus[$status]['canonical_opportunity_ids'] = array_values(array_unique($byCanonicalStatus[$status]['canonical_opportunity_ids']));
            });

            if ($canonicalRows->count() > 1) {
                $duplicateLinkRisks[] = [
                    'legacy_content_opportunity_id' => $legacyId,
                    'canonical_opportunity_ids' => $ids,
                ];
            }

            $reasons = $this->missingContextReasons($opportunity);

            if ($reasons !== []) {
                $missingContext[] = [
                    'legacy_content_opportunity_id' => $legacyId,
                    'reasons' => $reasons,
                ];
            }
        });

        $canonicalIds = array_values(array_unique($canonicalIds));

        return [
            'run_id' => (string) $run->id,
            'workspace_id' => $run->workspace_id ? (string) $run->workspace_id : null,
            'client_site_id' => $run->client_site_id ? (string) $run->client_site_id : null,
            'run_status' => (string) $run->status,
            'legacy_status_filter' => $legacyStatus,
            'legacy_opportunity_count' => $opportunities->count(),
            'linked_canonical_opportunity_count' => count($canonicalIds),
            'linked_candidate_count' => $linked,
            'unlinked_candidate_count' => $opportunities->count() - $linked,
            'duplicate_link_risk_count' => count($duplicateLinkRisks),
            'missing_context_count' => count($missingContext),
            'canonical_opportunity_ids_by_legacy_status' => $byLegacyStatus,
            'canonical_opportunity_ids_by_canonical_status' => $byCanonicalStatus,
            'missing_links' => $missingLinks,
            'missing_context' => $missingContext,
            'duplicate_link_risks' => $duplicateLinkRisks,
            'canonical_opportunity_id_samples' => array_slice($canonicalIds, 0, 10),
            'read_only' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function writeSummary(ContentOpportunityRun $run, array $summary): array
    {
        $result = $run->result ?? [];
        $result['canonical_reference_summary'] = [
            'schema' => 'content_opportunity_run_canonical_references.v1',
            'generated_at' => now()->toIso8601String(),
            'legacy_opportunity_count' => $summary['legacy_opportunity_count'],
            'linked_canonical_opportunity_count' => $summary['linked_canonical_opportunity_count'],
            'linked_candidate_count' => $summary['linked_candidate_count'],
            'unlinked_candidate_count' => $summary['unlinked_candidate_count'],
            'duplicate_link_risk_count' => $summary['duplicate_link_risk_count'],
            'missing_context_count' => $summary['missing_context_count'],
            'canonical_opportunity_ids_by_legacy_status' => $summary['canonical_opportunity_ids_by_legacy_status'],
            'canonical_opportunity_ids_by_canonical_status' => $summary['canonical_opportunity_ids_by_canonical_status'],
            'canonical_opportunity_id_samples' => $summary['canonical_opportunity_id_samples'],
        ];

        $run->forceFill(['result' => $result])->save();

        return $result['canonical_reference_summary'];
    }

    /**
     * @return EloquentCollection<int, ContentOpportunity>
     */
    private function legacyOpportunities(ContentOpportunityRun $run, ?string $legacyStatus): EloquentCollection
    {
        return ContentOpportunity::query()
            ->where('content_opportunity_run_id', $run->id)
            ->when($legacyStatus, fn ($query, string $status) => $query->where('status', $status))
            ->orderBy('status')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  EloquentCollection<int, ContentOpportunity>  $opportunities
     * @return Collection<string, Collection<int, Opportunity>>
     */
    private function canonicalOpportunitiesByLegacyId(EloquentCollection $opportunities): Collection
    {
        $legacyIds = $opportunities
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values();

        if ($legacyIds->isEmpty()) {
            return collect();
        }

        return Opportunity::query()
            ->whereIn('content_opportunity_id', $legacyIds)
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Opportunity $opportunity): string => (string) $opportunity->content_opportunity_id);
    }

    /**
     * @return array<int, string>
     */
    private function missingContextReasons(ContentOpportunity $opportunity): array
    {
        return array_values(array_filter([
            $opportunity->workspace_id ? null : 'missing_workspace_id',
            $opportunity->title ? null : 'missing_title',
            $opportunity->status ? null : 'missing_status',
            $opportunity->dedupe_hash ? null : 'missing_dedupe_hash',
        ]));
    }
}
