<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

use App\Enums\AgenticMarketingActionType;
use App\Enums\AgenticMarketingOpportunityType;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingOpportunity;
use App\Models\Opportunity;
use Illuminate\Support\Collection;

class AgenticOpportunityActionDedupeInspectionService
{
    public function __construct(
        private readonly AgenticOpportunityActionSignatureService $signatures,
        private readonly AgenticOpportunityCanonicalMappingService $mapping,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function inspect(AgenticMarketingOpportunity $opportunity): array
    {
        $opportunity->loadMissing('objective');
        $mapping = $this->mapping->mapExisting($opportunity);
        $canonical = $this->linkedCanonicalOpportunity($opportunity, $linkBlockedReasons);
        $actions = $this->actions($opportunity);
        $openActions = $actions->filter(fn (AgenticMarketingAction $action): bool => AgenticMarketingAction::isOpenStatus((string) $action->status));
        $actionRows = $actions->map(fn (AgenticMarketingAction $action): array => $this->actionRow($action))->values();
        $candidateRows = $this->candidateRows($opportunity, $canonical, $openActions);
        $duplicateRisks = $this->duplicateRisks($actionRows, $candidateRows);
        $blockedReasons = array_values(array_unique(array_merge(
            $linkBlockedReasons,
            $mapping->blockedReasons,
            $actionRows->flatMap(fn (array $row): array => $row['canonical_equivalent_signature']['blocked_reasons'])->all(),
            $candidateRows->flatMap(fn (array $row): array => $row['blocked_reasons'])->all(),
        )));

        return [
            'legacy_agentic_opportunity_id' => (string) $opportunity->id,
            'canonical_opportunity_id' => $canonical?->id ? (string) $canonical->id : null,
            'objective_id' => (string) $opportunity->objective_id,
            'workspace_id' => $opportunity->objective?->workspace_id ? (string) $opportunity->objective->workspace_id : null,
            'client_site_id' => $opportunity->objective?->client_site_id ? (string) $opportunity->objective->client_site_id : null,
            'detector_key' => $mapping->detectorKey,
            'agentic_type' => (string) $opportunity->type,
            'linked' => $canonical !== null,
            'open_action_count' => $openActions->count(),
            'existing_action_count' => $actions->count(),
            'existing_actions_by_action_type' => $actions->groupBy('action_type')->map->count()->all(),
            'current_action_dedupe_keys' => $actionRows->map(fn (array $row): array => $row['current_dedupe_keys'])->all(),
            'canonical_equivalent_signatures' => $actionRows->map(fn (array $row): array => $row['canonical_equivalent_signature'])->all(),
            'actions' => $actionRows->all(),
            'duplicate_risks' => $duplicateRisks,
            'duplicate_risk_count' => count($duplicateRisks),
            'safe_future_canonical_action_candidates' => $candidateRows->filter(fn (array $row): bool => $row['safe'])->values()->all(),
            'safe_future_canonical_action_candidate_count' => $candidateRows->where('safe', true)->count(),
            'blocked_reasons' => $blockedReasons,
            'blocked' => $blockedReasons !== [],
            'signature_samples' => $candidateRows
                ->pluck('signature')
                ->merge($actionRows->pluck('canonical_equivalent_signature.signature'))
                ->filter()
                ->unique()
                ->take(5)
                ->values()
                ->all(),
        ];
    }

    private function linkedCanonicalOpportunity(AgenticMarketingOpportunity $opportunity, ?array &$blockedReasons): ?Opportunity
    {
        $blockedReasons = [];
        $linked = Opportunity::query()
            ->where('agentic_marketing_opportunity_id', $opportunity->id)
            ->orderBy('id')
            ->get();

        if ($linked->count() > 1) {
            $blockedReasons[] = 'multiple_canonical_opportunities_linked_to_agentic_row';

            return null;
        }

        return $linked->first();
    }

    /**
     * @return Collection<int,AgenticMarketingAction>
     */
    private function actions(AgenticMarketingOpportunity $opportunity): Collection
    {
        return AgenticMarketingAction::query()
            ->where('opportunity_id', $opportunity->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<string,mixed>
     */
    private function actionRow(AgenticMarketingAction $action): array
    {
        $signature = $this->signatures->forAction($action);

        return [
            'id' => (string) $action->id,
            'action_type' => (string) $action->action_type,
            'status' => (string) $action->status,
            'open' => AgenticMarketingAction::isOpenStatus((string) $action->status),
            'current_dedupe_keys' => [
                'action_id' => (string) $action->id,
                'action_type' => (string) $action->action_type,
                'payload_hash' => $action->payload_hash,
                'dedupe_hash' => $action->dedupe_hash,
                'open_dedupe_hash' => $action->open_dedupe_hash,
            ],
            'source_fields' => [
                'objective_id' => (string) $action->objective_id,
                'opportunity_id' => $action->opportunity_id ? (string) $action->opportunity_id : null,
                'content_id' => $action->content_id ? (string) $action->content_id : null,
                'payload_content_id' => data_get($action->payload, 'content_id'),
                'payload_client_site_id' => data_get($action->payload, 'client_site_id'),
                'payload_planner' => data_get($action->payload, 'planning.planner'),
                'payload_source_opportunity_type' => data_get($action->payload, 'planning.source_opportunity_type'),
            ],
            'canonical_equivalent_signature' => $signature,
        ];
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function candidateRows(AgenticMarketingOpportunity $opportunity, ?Opportunity $canonical, Collection $openActions): Collection
    {
        return collect($this->candidateActionTypes($opportunity))
            ->map(function (string $actionType) use ($opportunity, $canonical, $openActions): array {
                $signature = $this->signatures->forLegacyOpportunity($opportunity, $actionType);
                $matchingOpenActionIds = $signature['signature']
                    ? $openActions
                        ->filter(fn (AgenticMarketingAction $action): bool => $this->signatures->forAction($action)['signature'] === $signature['signature'])
                        ->map(fn (AgenticMarketingAction $action): string => (string) $action->id)
                        ->values()
                        ->all()
                    : [];

                $blockedReasons = array_values(array_filter(array_merge(
                    $signature['blocked_reasons'],
                    $canonical ? [] : ['missing_canonical_link'],
                )));

                return [
                    'action_type' => $actionType,
                    'signature' => $signature['signature'],
                    'signature_parts' => $signature['signature_parts'],
                    'matching_open_action_ids' => $matchingOpenActionIds,
                    'safe' => $blockedReasons === [] && $matchingOpenActionIds === [],
                    'blocked_reasons' => $blockedReasons,
                ];
            });
    }

    /**
     * @return array<int,string>
     */
    private function duplicateRisks(Collection $actionRows, Collection $candidateRows): array
    {
        $risks = [];

        $actionRows
            ->filter(fn (array $row): bool => $row['open'] && filled($row['canonical_equivalent_signature']['signature']))
            ->groupBy(fn (array $row): string => (string) $row['canonical_equivalent_signature']['signature'])
            ->each(function (Collection $rows, string $signature) use (&$risks): void {
                if ($rows->count() > 1) {
                    $risks[] = [
                        'type' => 'multiple_open_actions_same_canonical_equivalent_signature',
                        'signature' => $signature,
                        'action_ids' => $rows->pluck('id')->values()->all(),
                    ];
                }
            });

        foreach ($candidateRows as $candidate) {
            if (($candidate['matching_open_action_ids'] ?? []) !== []) {
                $risks[] = [
                    'type' => 'future_canonical_candidate_matches_existing_open_agentic_action',
                    'signature' => $candidate['signature'],
                    'action_type' => $candidate['action_type'],
                    'action_ids' => $candidate['matching_open_action_ids'],
                ];
            }
        }

        return $risks;
    }

    /**
     * @return array<int,string>
     */
    private function candidateActionTypes(AgenticMarketingOpportunity $opportunity): array
    {
        return match (AgenticMarketingOpportunityType::tryFrom((string) $opportunity->type)) {
            AgenticMarketingOpportunityType::Refresh => [AgenticMarketingActionType::RefreshArticle->value],
            AgenticMarketingOpportunityType::AnswerCoverage => [AgenticMarketingActionType::AddAnswerBlock->value],
            AgenticMarketingOpportunityType::InternalLinks => [AgenticMarketingActionType::ImproveInternalLinks->value],
            AgenticMarketingOpportunityType::LocaleExpansion => [AgenticMarketingActionType::CreateLocaleVariant->value],
            AgenticMarketingOpportunityType::Metadata => [AgenticMarketingActionType::UpdateMeta->value],
            AgenticMarketingOpportunityType::Schema => [AgenticMarketingActionType::AddSchema->value],
            AgenticMarketingOpportunityType::SeoIndexability => [
                AgenticMarketingActionType::UpdateMeta->value,
                AgenticMarketingActionType::AddSchema->value,
            ],
            AgenticMarketingOpportunityType::NewArticle,
            AgenticMarketingOpportunityType::ContentNetwork => [AgenticMarketingActionType::CreateArticle->value],
            AgenticMarketingOpportunityType::AiVisibility => [
                AgenticMarketingActionType::AddAnswerBlock->value,
                AgenticMarketingActionType::UpdateMeta->value,
            ],
            default => [],
        };
    }
}
