<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

use App\Enums\OpportunityStatus;

class AgenticOpportunityLifecycleMap
{
    /**
     * @return array<string,array<string,mixed>>
     */
    public function mappings(): array
    {
        return [
            'open' => [
                'candidate_canonical_status' => [
                    OpportunityStatus::OPEN->value,
                    OpportunityStatus::REVIEWING->value,
                ],
                'blocked_reason' => 'agentic_open_is_executable_input',
                'notes' => 'Agentic open rows can still drive planner and execution input, so canonical open/reviewing is candidate context only.',
            ],
            'dismissed' => [
                'candidate_canonical_status' => [
                    OpportunityStatus::DISMISSED->value,
                ],
                'blocked_reason' => 'dismissed_agentic_rows_may_still_have_execution_state',
                'notes' => 'Dismissed can coexist with historical or active Agentic actions and execution pipelines.',
            ],
            'completed' => [
                'candidate_canonical_status' => [
                    OpportunityStatus::ACTIONED->value,
                    OpportunityStatus::RESOLVED->value,
                ],
                'blocked_reason' => 'agentic_completed_scope_is_ambiguous',
                'notes' => 'Completed can mean action-level completion, pipeline-level completion, or simply no longer open.',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function map(mixed $status): array
    {
        $legacyStatus = $this->normalize($status);
        $mapping = $this->mappings()[$legacyStatus] ?? null;

        if (! $mapping) {
            return [
                'legacy_status' => $legacyStatus,
                'candidate_canonical_status' => [],
                'reverse_safe' => false,
                'sync_safe' => false,
                'blocked_reason' => 'unmapped_agentic_status',
                'notes' => 'Unknown Agentic status; Phase 3J does not infer canonical lifecycle context.',
                'unmapped' => true,
            ];
        }

        return [
            'legacy_status' => $legacyStatus,
            'candidate_canonical_status' => $mapping['candidate_canonical_status'],
            'reverse_safe' => false,
            'sync_safe' => false,
            'blocked_reason' => $mapping['blocked_reason'],
            'notes' => $mapping['notes'],
            'unmapped' => false,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function rows(): array
    {
        return collect(array_keys($this->mappings()))
            ->map(fn (string $status): array => $this->map($status))
            ->values()
            ->all();
    }

    private function normalize(mixed $status): string
    {
        if ($status instanceof \BackedEnum) {
            $status = $status->value;
        }

        return strtolower(trim((string) $status));
    }
}
