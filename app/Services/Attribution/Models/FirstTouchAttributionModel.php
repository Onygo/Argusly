<?php

namespace App\Services\Attribution\Models;

use App\Contracts\Attribution\AttributionModel;
use App\Models\AttributionConversion;
use App\Services\Attribution\Models\Concerns\AllocatesAttributionCredit;
use Illuminate\Support\Collection;

class FirstTouchAttributionModel implements AttributionModel
{
    use AllocatesAttributionCredit;

    public function key(): string
    {
        return 'first_touch';
    }

    public function label(): string
    {
        return 'First touch';
    }

    public function allocate(Collection $matches, AttributionConversion $conversion, array $settings = []): Collection
    {
        unset($conversion, $settings);

        $matches = $matches->sortBy(fn (array $match) => $match['touchpoint']->occurred_at)->values();
        $weights = array_fill(0, $matches->count(), 0.0);
        $weights[0] = 1.0;

        return $this->allocateWeights($matches, $weights, $this->key());
    }
}
