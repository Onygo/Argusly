<?php

namespace App\Services\Mos\Providers;

use App\Models\SignalDetection;
use App\Models\SignalEvent;
use App\Models\SignalSource;
use App\Services\Mos\Contracts\MosProvider;
use App\Services\Mos\MosDomain;
use App\Services\SignalIntelligence\SignalSourceRegistry;

class SignalIntelligenceMosProvider implements MosProvider
{
    public function __construct(
        private readonly SignalSourceRegistry $sources,
    ) {}

    public function key(): string
    {
        return 'signal-intelligence';
    }

    public function domain(): string
    {
        return MosDomain::SIGNAL;
    }

    public function label(): string
    {
        return 'Signal Intelligence';
    }

    public function capabilities(): array
    {
        return [
            'detect_signals',
            'ingest_events',
            'score_signals',
            'promote_to_opportunities',
        ];
    }

    public function priority(): int
    {
        return 100;
    }

    public function metadata(): array
    {
        return [
            'canonical_models' => [
                SignalSource::class,
                SignalEvent::class,
                SignalDetection::class,
            ],
            'source_types' => $this->sources->sourceTypes(),
            'registry' => $this->sources::class,
            'backwards_compatible' => true,
        ];
    }

    public function sources(): SignalSourceRegistry
    {
        return $this->sources;
    }
}
