<?php

namespace App\View\Components;

use App\Models\Workspace;
use App\Services\Journey\WorkspaceJourneyService;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class IntelligenceJourney extends Component
{
    /**
     * @var array<string,mixed>|null
     */
    public ?array $journey;

    public function __construct(
        public readonly ?Workspace $workspace = null,
        public readonly bool $compact = false,
    ) {
        $this->journey = $workspace
            ? app(WorkspaceJourneyService::class)->forWorkspace($workspace)
            : null;
    }

    public function render(): View
    {
        return view('components.intelligence-journey');
    }
}
