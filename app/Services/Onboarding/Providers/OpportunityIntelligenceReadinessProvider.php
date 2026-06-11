<?php

namespace App\Services\Onboarding\Providers;

use App\Enums\OpportunitySignalSource;
use App\Models\Opportunity;
use App\Models\OpportunitySignal;
use App\Models\SignalDetection;
use App\Models\Workspace;
use App\Services\Onboarding\ModuleReadinessResult;
use App\Services\Onboarding\ReadinessRequirement;

class OpportunityIntelligenceReadinessProvider extends BaseReadinessProvider
{
    public function __construct(private readonly SignalIntelligenceReadinessProvider $signals)
    {
    }

    public function key(): string { return 'opportunity_intelligence'; }

    public function label(): string { return 'Opportunity Intelligence'; }

    public function description(): string { return 'Clusters promoted signals into actionable opportunities.'; }

    public function evaluate(Workspace $workspace): ModuleReadinessResult
    {
        $signalReady = in_array($this->signals->evaluate($workspace)->status, ['ready', 'active'], true);
        $detections = SignalDetection::query()->where('workspace_id', $workspace->id)->count();
        $promoted = OpportunitySignal::query()
            ->where('workspace_id', $workspace->id)
            ->where('source', OpportunitySignalSource::SIGNAL_INTELLIGENCE->value)
            ->whereNotNull('metadata->signal_detection_id')
            ->count();
        $opportunities = Opportunity::query()->where('workspace_id', $workspace->id)->count();

        $requirements = [
            new ReadinessRequirement('signal_ready', 'Make Signal Intelligence ready', 'Opportunity Intelligence depends on enough reviewed signal context.', $signalReady, 'required', 'Open Signal Intelligence', $this->routeOrNull('app.signal-intelligence.index')),
            new ReadinessRequirement('detections', 'Create signal detections', 'Detections are the reviewed input layer.', $detections >= 1, 'required', 'Open Signal Intelligence', $this->routeOrNull('app.signal-intelligence.index')),
            new ReadinessRequirement('promoted_signals', 'Promote a detection', 'Promoted detections become OpportunitySignals.', $promoted >= 1, 'required', 'Open Signal Intelligence', $this->routeOrNull('app.signal-intelligence.index')),
        ];

        $actions = [
            $this->action(
                $promoted >= 1 ? 'Review promoted signals' : 'Open Signal Intelligence',
                $promoted >= 1 ? 'Promoted signals are ready to be clustered.' : 'Create, review, and promote detections first.',
                $this->routeOrNull('app.signal-intelligence.index'),
                'primary',
            ),
        ];

        return $this->result($requirements, $actions, 'Opportunity Intelligence starts after Signal Intelligence detections are reviewed and promoted.', $opportunities > 0);
    }
}
