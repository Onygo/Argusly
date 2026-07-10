<?php

namespace App\Contracts\Attribution;

use App\Models\AttributionConversion;
use App\Models\AttributionTouchpoint;
use Illuminate\Support\Collection;

interface AttributionModel
{
    public function key(): string;

    public function label(): string;

    /**
     * @param  Collection<int, array{touchpoint: AttributionTouchpoint, match_confidence: string, score: int}>  $matches
     * @param  array<string, mixed>  $settings
     * @return Collection<int, array{touchpoint: AttributionTouchpoint, credit: float, metadata: array<string, mixed>}>
     */
    public function allocate(Collection $matches, AttributionConversion $conversion, array $settings = []): Collection;
}
