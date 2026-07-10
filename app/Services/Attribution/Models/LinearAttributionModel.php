<?php

namespace App\Services\Attribution\Models;

use App\Contracts\Attribution\AttributionModel;
use App\Models\AttributionConversion;
use App\Services\Attribution\Models\Concerns\AllocatesAttributionCredit;
use Illuminate\Support\Collection;

class LinearAttributionModel implements AttributionModel
{
    use AllocatesAttributionCredit;

    public function key(): string
    {
        return 'linear';
    }

    public function label(): string
    {
        return 'Linear';
    }

    public function allocate(Collection $matches, AttributionConversion $conversion, array $settings = []): Collection
    {
        unset($conversion, $settings);

        $matches = $matches->sortBy(fn (array $match) => $match['touchpoint']->occurred_at)->values();

        return $this->allocateWeights($matches, array_fill(0, $matches->count(), 1.0), $this->key());
    }
}
