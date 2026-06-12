<?php

namespace App\Services\Growth;

use App\Models\ContentPublication;
use App\Models\GrowthProgram;
use App\Models\ProgrammaticOpportunity;
use App\Models\ProgrammaticPublicationPlanItem;
use App\Models\ProgrammaticPublicationReadiness;
use App\Models\Workspace;

class ProgrammaticGrowthBetaSummary
{
    /**
     * @return array<string,int>
     */
    public function forWorkspace(Workspace $workspace): array
    {
        $workspaceId = (string) $workspace->id;

        return [
            'active_growth_programs' => GrowthProgram::query()
                ->where('workspace_id', $workspaceId)
                ->whereNotIn('status', ['published', 'measured'])
                ->count(),
            'opportunities_ready_for_scaling' => ProgrammaticOpportunity::query()
                ->where('workspace_id', $workspaceId)
                ->whereIn('status', [
                    ProgrammaticOpportunity::STATUS_DETECTED,
                    ProgrammaticOpportunity::STATUS_VALIDATED,
                    ProgrammaticOpportunity::STATUS_PLANNED,
                ])
                ->count(),
            'content_assets_ready' => ProgrammaticPublicationReadiness::query()
                ->where('workspace_id', $workspaceId)
                ->whereIn('status', [
                    ProgrammaticPublicationReadiness::STATUS_READY,
                    ProgrammaticPublicationReadiness::STATUS_APPROVED,
                ])
                ->count(),
            'scheduled_publication_records' => ContentPublication::query()
                ->whereHas('content', fn ($query) => $query->where('workspace_id', $workspaceId))
                ->where('delivery_status', ContentPublication::STATUS_PENDING)
                ->where('remote_status', ContentPublication::REMOTE_SCHEDULED)
                ->count(),
            'blocked_items' => $this->blockedItems($workspaceId),
        ];
    }

    private function blockedItems(string $workspaceId): int
    {
        $readiness = ProgrammaticPublicationReadiness::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', ProgrammaticPublicationReadiness::STATUS_BLOCKED)
            ->count();

        $planItems = ProgrammaticPublicationPlanItem::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('status', [
                ProgrammaticPublicationPlanItem::STATUS_CONFLICT,
                ProgrammaticPublicationPlanItem::STATUS_NEEDS_ATTENTION,
                ProgrammaticPublicationPlanItem::STATUS_FAILED,
            ])
            ->count();

        return $readiness + $planItems;
    }
}
