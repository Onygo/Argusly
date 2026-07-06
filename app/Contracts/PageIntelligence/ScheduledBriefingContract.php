<?php

namespace App\Contracts\PageIntelligence;

use App\Models\PageIntelligenceReport;
use App\Models\User;
use App\Models\Workspace;

interface ScheduledBriefingContract
{
    /**
     * Prepare a report snapshot for a future scheduled briefing caller.
     *
     * Implementations must not deliver email, create recurring schedules, or add report types.
     *
     * @param  array<string,mixed>  $options
     */
    public function prepare(Workspace $workspace, string $reportType, array $options = [], ?User $user = null): PageIntelligenceReport;
}
