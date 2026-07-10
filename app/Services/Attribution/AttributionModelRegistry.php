<?php

namespace App\Services\Attribution;

use App\Contracts\Attribution\AttributionModel;
use App\Services\Attribution\Models\CampaignInfluencedAttributionModel;
use App\Services\Attribution\Models\FirstTouchAttributionModel;
use App\Services\Attribution\Models\LastTouchAttributionModel;
use App\Services\Attribution\Models\LinearAttributionModel;
use App\Services\Attribution\Models\PositionBasedAttributionModel;
use App\Services\Attribution\Models\TimeDecayAttributionModel;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class AttributionModelRegistry
{
    /**
     * @var array<string, class-string<AttributionModel>>
     */
    private array $models = [
        'first_touch' => FirstTouchAttributionModel::class,
        'last_touch' => LastTouchAttributionModel::class,
        'linear' => LinearAttributionModel::class,
        'time_decay' => TimeDecayAttributionModel::class,
        'position_based' => PositionBasedAttributionModel::class,
        'campaign_influenced' => CampaignInfluencedAttributionModel::class,
    ];

    public function __construct(private readonly Container $container) {}

    public function has(string $key): bool
    {
        return isset($this->models[$key]);
    }

    public function resolve(string $key): AttributionModel
    {
        $modelClass = $this->models[$key] ?? null;

        if ($modelClass === null) {
            throw new InvalidArgumentException("No attribution model is registered for [{$key}].");
        }

        return $this->container->make($modelClass);
    }

    /**
     * @return Collection<int, AttributionModel>
     */
    public function all(): Collection
    {
        return collect(array_keys($this->models))
            ->map(fn (string $key): AttributionModel => $this->resolve($key));
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->models);
    }
}
