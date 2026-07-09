<?php

namespace App\Services\Mos\Providers;

use App\Models\MarketingInitiative;
use App\Models\MarketingObjective;
use App\Models\MarketingOperatingLink;
use App\Models\MarketingPriority;
use App\Models\MarketingReview;
use App\Models\MarketingTheme;
use App\Models\MarketingTimelineEvent;
use App\Models\MarketingWorkflow;
use App\Services\Mos\Contracts\MosProvider;
use App\Services\Mos\MosDomain;
use App\Services\Mos\OperatingSystem\MarketingOperatingSystem;

class MarketingOperatingSystemMosProvider implements MosProvider
{
    public function __construct(
        private readonly MarketingOperatingSystem $operatingSystem,
    ) {}

    public function key(): string
    {
        return 'marketing-operating-system';
    }

    public function domain(): string
    {
        return MosDomain::WORKFLOW;
    }

    public function label(): string
    {
        return 'Marketing Operating System';
    }

    public function capabilities(): array
    {
        return [
            'orchestrate_objectives',
            'orchestrate_initiatives',
            'link_recommendations',
            'integrate_reports',
            'integrate_briefings',
            'project_universal_resources',
            'record_operating_timeline',
        ];
    }

    public function priority(): int
    {
        return 85;
    }

    public function metadata(): array
    {
        return [
            'engine' => $this->operatingSystem::class,
            'models' => [
                'objective' => MarketingObjective::class,
                'initiative' => MarketingInitiative::class,
                'theme' => MarketingTheme::class,
                'priority' => MarketingPriority::class,
                'workflow' => MarketingWorkflow::class,
                'timeline' => MarketingTimelineEvent::class,
                'review' => MarketingReview::class,
                'link' => MarketingOperatingLink::class,
            ],
            'uses_universal_ui' => true,
            'creates_isolated_dashboards' => false,
            'provider_agnostic' => true,
        ];
    }
}
