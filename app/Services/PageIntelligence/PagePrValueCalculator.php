<?php

namespace App\Services\PageIntelligence;

use App\Models\PagePrValue;
use App\Models\PageSnapshot;
use App\Services\PageIntelligence\PrValue\ArguslyPrValueModel;
use App\Services\PageIntelligence\PrValue\PrValueModel;
use App\Services\PageIntelligence\PrValue\TraditionalAvePrValueModel;
use App\Services\PageIntelligence\PrValue\WeightedEarnedMediaValueModel;
use Illuminate\Support\Collection;

class PagePrValueCalculator
{
    /**
     * @param array<int,PrValueModel>|null $models
     */
    public function __construct(private ?array $models = null) {}

    /**
     * @return Collection<int, PagePrValue>
     */
    public function calculate(PageSnapshot $snapshot, ?array $modelKeys = null): Collection
    {
        $snapshot = $snapshot->loadMissing(['page.source', 'contentExtraction']);
        $models = collect($this->models())
            ->when($modelKeys !== null, fn (Collection $models): Collection => $models->filter(
                fn (PrValueModel $model): bool => in_array($model->key(), $modelKeys, true)
            ));

        return $models
            ->map(function (PrValueModel $model) use ($snapshot): PagePrValue {
                $result = $model->calculate($snapshot);

                return PagePrValue::query()->updateOrCreate(
                    [
                        'page_snapshot_id' => $snapshot->id,
                        'model_key' => $model->key(),
                        'model_version' => $model->version(),
                    ],
                    [
                        'organization_id' => $snapshot->organization_id,
                        'workspace_id' => $snapshot->workspace_id,
                        'client_site_id' => $snapshot->client_site_id,
                        'monitored_page_id' => $snapshot->monitored_page_id,
                        'page_content_extraction_id' => $snapshot->contentExtraction?->id,
                        'score' => $result['score'],
                        'estimated_value_amount' => $result['estimated_value_amount'],
                        'currency' => $result['currency'],
                        'confidence' => $result['confidence'],
                        'breakdown_json' => $result['breakdown'],
                        'calculated_at' => now(),
                        'metadata_json' => [
                            'calculation_policy' => 'update_by_snapshot_model_version',
                            'model_key' => $model->key(),
                            'model_version' => $model->version(),
                        ],
                    ]
                );
            })
            ->values();
    }

    /**
     * @return array<int,PrValueModel>
     */
    public function models(): array
    {
        return $this->models ?? [
            app(TraditionalAvePrValueModel::class),
            app(WeightedEarnedMediaValueModel::class),
            app(ArguslyPrValueModel::class),
        ];
    }
}
