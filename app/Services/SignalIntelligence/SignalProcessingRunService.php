<?php

namespace App\Services\SignalIntelligence;

use App\Enums\SignalStatus;
use App\Models\ClientSite;
use App\Models\SignalProcessingRun;
use App\Models\SignalSource;
use App\Models\Workspace;

class SignalProcessingRunService
{
    /**
     * @param array<string,mixed> $input
     */
    public function startRun(
        Workspace $workspace,
        string $runType,
        ?SignalSource $source = null,
        ?ClientSite $clientSite = null,
        array $input = []
    ): SignalProcessingRun {
        return SignalProcessingRun::query()->create([
            'organization_id' => $workspace->organization_id,
            'workspace_id' => $workspace->id,
            'client_site_id' => $clientSite?->id,
            'signal_source_id' => $source?->id,
            'run_type' => $runType,
            'status' => SignalStatus::PROCESSING->value,
            'input' => $input,
            'items_seen' => 0,
            'items_created' => 0,
            'signals_created' => 0,
            'detections_created' => 0,
            'started_at' => now(),
        ]);
    }

    /**
     * @param array<string,mixed> $result
     */
    public function markSucceeded(SignalProcessingRun $run, array $result = []): SignalProcessingRun
    {
        $run->forceFill([
            'status' => SignalStatus::RESOLVED->value,
            'finished_at' => now(),
            'items_seen' => $result['items_seen'] ?? $run->items_seen,
            'items_created' => $result['items_created'] ?? $run->items_created,
            'signals_created' => $result['signals_created'] ?? $run->signals_created,
            'detections_created' => $result['detections_created'] ?? $run->detections_created,
            'result' => $result,
            'failure_reason' => null,
        ])->save();

        return $run->refresh();
    }

    /**
     * @param array<string,mixed> $result
     */
    public function markFailed(SignalProcessingRun $run, string $reason, array $result = []): SignalProcessingRun
    {
        $run->forceFill([
            'status' => SignalStatus::DISMISSED->value,
            'finished_at' => now(),
            'failure_reason' => $reason,
            'result' => $result,
        ])->save();

        return $run->refresh();
    }
}
