<?php

namespace App\Services\Mos\Providers;

use App\Models\Opportunity;
use App\Models\OpportunitySignal;
use App\Services\Mos\Contracts\MosProvider;
use App\Services\Mos\MosDomain;
use App\Services\OpportunityIntelligence\OpportunityIntelligenceEngine;

class OpportunityIntelligenceMosProvider implements MosProvider
{
    public function __construct(
        private readonly OpportunityIntelligenceEngine $engine,
    ) {}

    public function key(): string
    {
        return 'opportunity-intelligence';
    }

    public function domain(): string
    {
        return MosDomain::OPPORTUNITY;
    }

    public function label(): string
    {
        return 'Opportunity Intelligence';
    }

    public function capabilities(): array
    {
        return [
            'generate_opportunities',
            'prioritize_opportunities',
            'recommend_actions',
            'cluster_signals',
        ];
    }

    public function priority(): int
    {
        return 100;
    }

    public function metadata(): array
    {
        return [
            'canonical_model' => Opportunity::class,
            'signal_model' => OpportunitySignal::class,
            'engine' => $this->engine::class,
            'backwards_compatible' => true,
        ];
    }

    public function engine(): OpportunityIntelligenceEngine
    {
        return $this->engine;
    }
}
