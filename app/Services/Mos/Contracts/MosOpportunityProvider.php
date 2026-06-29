<?php

namespace App\Services\Mos\Contracts;

use App\Services\Mos\Opportunity\CanonicalOpportunityCandidate;
use Illuminate\Database\Eloquent\Model;

interface MosOpportunityProvider extends MosProvider
{
    public function sourceModel(): ?string;

    public function sourceType(): string;

    /**
     * @return array<int, string>
     */
    public function supportedOpportunityTypes(): array;

    /**
     * @return array<int, string>
     */
    public function supportedLifecycleStates(): array;

    public function canEmitSignals(): bool;

    public function canEmitCanonicalOpportunities(): bool;

    public function isReadOnly(): bool;

    public function migrationReadiness(): string;

    public function classification(): string;

    public function riskLevel(): string;

    public function supports(Model $source): bool;

    public function toCanonicalOpportunity(Model $source): CanonicalOpportunityCandidate;

    /**
     * @return array<int, string>
     */
    public function missingFields(Model $source): array;
}
