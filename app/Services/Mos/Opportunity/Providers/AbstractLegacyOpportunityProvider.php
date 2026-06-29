<?php

namespace App\Services\Mos\Opportunity\Providers;

use App\Services\Mos\Contracts\MosOpportunityProvider;
use App\Services\Mos\MosDomain;
use App\Services\Mos\Opportunity\Support\MapsCanonicalOpportunityFields;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractLegacyOpportunityProvider implements MosOpportunityProvider
{
    use MapsCanonicalOpportunityFields;

    public function domain(): string
    {
        return MosDomain::OPPORTUNITY;
    }

    public function capabilities(): array
    {
        return array_values(array_filter([
            'describe_legacy_opportunity_candidates',
            $this->canEmitCanonicalOpportunities() ? 'emit_canonical_opportunity_payload' : null,
            $this->canEmitSignals() ? 'emit_opportunity_signal_payload' : null,
        ]));
    }

    public function priority(): int
    {
        return 10;
    }

    public function sourceType(): string
    {
        return $this->key();
    }

    public function canEmitSignals(): bool
    {
        return false;
    }

    public function canEmitCanonicalOpportunities(): bool
    {
        return true;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function supports(Model $source): bool
    {
        $sourceModel = $this->sourceModel();

        return $sourceModel !== null && $source instanceof $sourceModel;
    }

    public function metadata(): array
    {
        return [
            'source_model' => $this->sourceModel(),
            'source_type' => $this->sourceType(),
            'supported_opportunity_types' => $this->supportedOpportunityTypes(),
            'supported_lifecycle_states' => $this->supportedLifecycleStates(),
            'can_emit_signals' => $this->canEmitSignals(),
            'can_emit_canonical_opportunities' => $this->canEmitCanonicalOpportunities(),
            'read_only' => $this->isReadOnly(),
            'migration_readiness' => $this->migrationReadiness(),
            'classification' => $this->classification(),
            'risk_level' => $this->riskLevel(),
        ];
    }
}
