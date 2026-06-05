<?php

namespace App\Jobs\DraftComparison;

use App\Models\Draft;
use App\Models\DraftComparison;
use App\Models\DraftComparisonItem;
use App\Models\DraftComparisonVariant;
use App\Services\DraftComparison\DraftComparisonCreditManager;
use App\Services\DraftComparison\DraftComparisonModelCatalog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StartDraftComparisonJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 900;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function __construct(
        public readonly string $comparisonId,
    ) {}

    public function uniqueId(): string
    {
        return 'draft_compare:start:' . $this->comparisonId;
    }

    public function handle(
        DraftComparisonModelCatalog $modelCatalog,
        DraftComparisonCreditManager $creditManager,
    ): void {
        $comparison = DraftComparison::query()
            ->with(['items', 'brief', 'content'])
            ->find($this->comparisonId);

        if (! $comparison || $comparison->isTerminal()) {
            return;
        }

        try {
            $selections = $this->resolveSelections($comparison, $modelCatalog);
            if ($selections === []) {
                throw new RuntimeException('No valid model selections found for draft comparison run.');
            }

            $this->assertSelectionCount((string) $comparison->mode, count($selections));

            $reserveAmount = $this->resolveReserveAmount($comparison, $selections);
            if ($reserveAmount > 0) {
                $creditManager->reserveForComparison(
                    comparison: $comparison,
                    amount: $reserveAmount,
                    userId: $comparison->created_by_user_id ? (int) $comparison->created_by_user_id : null,
                    metadata: [
                        'job' => self::class,
                        'comparison_mode' => (string) $comparison->mode,
                    ],
                );
            }

            $variantsToDispatch = DB::transaction(function () use ($comparison, $selections): array {
                $locked = DraftComparison::query()
                    ->with('items')
                    ->whereKey($comparison->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->isTerminal()) {
                    return [];
                }

                $locked->markQueued();

                $variantsToDispatch = [];

                foreach (array_values($selections) as $index => $selection) {
                    $provider = (string) ($selection['provider'] ?? '');
                    $model = (string) ($selection['model'] ?? '');
                    $label = (string) ($selection['label'] ?? ($provider . ' - ' . $model));
                    $creditCost = max(0, (int) ($selection['estimated_credit_cost'] ?? 0));

                    $variant = DraftComparisonVariant::query()
                        ->where('draft_comparison_id', $locked->id)
                        ->where('provider_key', $provider)
                        ->where('model_key', $model)
                        ->first();

                    if (! $variant) {
                        $variant = DraftComparisonVariant::query()->create([
                            'draft_comparison_id' => (string) $locked->id,
                            'provider_key' => $provider,
                            'model_key' => $model,
                            'display_name' => $label,
                            'sort_order' => $index + 1,
                            'status' => DraftComparisonVariant::STATUS_QUEUED,
                            'credit_cost' => $creditCost > 0 ? $creditCost : null,
                        ]);
                    } else {
                        $variant->fill([
                            'display_name' => $variant->display_name ?: $label,
                            'sort_order' => $variant->sort_order > 0 ? $variant->sort_order : ($index + 1),
                        ]);

                        if ($creditCost > 0 && ! $variant->credit_cost) {
                            $variant->credit_cost = $creditCost;
                        }

                        if (in_array((string) $variant->status, [DraftComparisonVariant::STATUS_PENDING, DraftComparisonVariant::STATUS_QUEUED], true)) {
                            $variant->status = DraftComparisonVariant::STATUS_QUEUED;
                        }

                        $variant->save();
                    }

                    $legacyItem = $this->findLegacyItemForSelection($locked, $provider, $model, $index + 1, $creditCost);

                    if (! $variant->draft_id && $legacyItem?->draft_id) {
                        $variant->draft_id = $legacyItem->draft_id;
                        $variant->save();
                    }

                    if ($variant->draft_id && $legacyItem && ! $legacyItem->draft_id) {
                        $legacyItem->draft_id = $variant->draft_id;
                        $legacyItem->save();
                    }

                    if ($variant->draft_id) {
                        $draft = Draft::query()->find($variant->draft_id);
                        if ($draft) {
                            $meta = is_array($draft->meta) ? $draft->meta : [];
                            $draftCompare = is_array(data_get($meta, 'draft_compare')) ? data_get($meta, 'draft_compare') : [];
                            $draftCompare['comparison_id'] = (string) $locked->id;
                            $draftCompare['variant_id'] = (string) $variant->id;
                            $draftCompare['legacy_item_id'] = (string) ($legacyItem?->id ?? data_get($draftCompare, 'legacy_item_id', ''));
                            $draftCompare['provider'] = (string) $variant->provider_key;
                            $draftCompare['model'] = (string) $variant->model_key;
                            $draftCompare['is_hybrid'] = false;
                            $draftCompare['comparison_credit_managed'] = true;
                            $meta['draft_compare'] = $draftCompare;
                            $meta['generation_provider_override'] = (string) $variant->provider_key;
                            $meta['generation_model_override'] = (string) $variant->model_key;
                            $draft->draft_comparison_id = (string) $locked->id;
                            $draft->draft_comparison_variant_id = (string) $variant->id;
                            $draft->meta = $meta;
                            $draft->save();
                        }
                    }

                    if (in_array((string) $variant->status, [
                        DraftComparisonVariant::STATUS_PENDING,
                        DraftComparisonVariant::STATUS_QUEUED,
                    ], true)) {
                        $variantsToDispatch[] = (string) $variant->id;
                    }
                }

                if ($variantsToDispatch !== []) {
                    $locked->markProcessing();
                }

                return $variantsToDispatch;
            });

            foreach ($variantsToDispatch as $variantId) {
                GenerateDraftComparisonVariantJob::dispatch($variantId)
                    ->onQueue('generation')
                    ->afterCommit();
            }

            if ($variantsToDispatch === []) {
                FinalizeDraftComparisonJob::dispatch((string) $comparison->id)
                    ->onQueue('generation')
                    ->afterCommit();
            }
        } catch (RuntimeException $exception) {
            $locked = DraftComparison::query()
                ->whereKey($comparison->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked || $locked->isTerminal()) {
                return;
            }

            $locked->markFailed();
            $locked->last_error = mb_substr($exception->getMessage(), 0, 5000);
            $locked->save();
            $creditManager->settleComparison($locked->fresh(), options: ['force' => true]);
        }
    }

    /**
     * @return array<int, array{provider:string,model:string,label:string,estimated_credit_cost:int}>
     */
    private function resolveSelections(DraftComparison $comparison, DraftComparisonModelCatalog $modelCatalog): array
    {
        $requested = collect((array) $comparison->requested_models_json)
            ->map(function (array $row): string {
                $provider = strtolower(trim((string) ($row['provider'] ?? '')));
                $model = trim((string) ($row['model'] ?? ''));
                if ($provider === '' || $model === '') {
                    return trim((string) ($row['key'] ?? ''));
                }

                return $provider . ':' . $model;
            })
            ->filter()
            ->values()
            ->all();

        if ($requested === []) {
            $requested = $comparison->items()
                ->orderBy('sort_order')
                ->get()
                ->map(fn (DraftComparisonItem $item): string => strtolower(trim((string) $item->provider)) . ':' . trim((string) $item->model))
                ->filter()
                ->values()
                ->all();
        }

        $resolved = $modelCatalog->resolveSelections($requested);
        if ($resolved === []) {
            return [];
        }

        $costByKey = $comparison->items()
            ->get()
            ->mapWithKeys(fn (DraftComparisonItem $item): array => [
                strtolower(trim((string) $item->provider)) . ':' . trim((string) $item->model) => max(0, (int) ($item->credit_cost ?? 0)),
            ]);

        return collect($resolved)
            ->map(function (array $selection) use ($costByKey): array {
                $key = strtolower(trim((string) $selection['provider'])) . ':' . trim((string) $selection['model']);

                return [
                    'provider' => strtolower(trim((string) $selection['provider'])),
                    'model' => trim((string) $selection['model']),
                    'label' => (string) ($selection['label'] ?? ($selection['provider'] . ' - ' . $selection['model'])),
                    'estimated_credit_cost' => max(0, (int) ($costByKey->get($key, 0))),
                ];
            })
            ->values()
            ->all();
    }

    private function resolveReserveAmount(DraftComparison $comparison, array $selections): int
    {
        $estimated = max(0, (int) ($comparison->estimated_credit_cost ?? 0));
        if ($estimated > 0) {
            return $estimated;
        }

        $legacyEstimated = max(0, (int) ($comparison->estimated_credits ?? 0));
        if ($legacyEstimated > 0) {
            return $legacyEstimated;
        }

        $sum = max(0, (int) $comparison->items()->sum('credit_cost'));
        if ($sum > 0) {
            return $sum;
        }

        return max(0, (int) collect($selections)->sum('estimated_credit_cost'));
    }

    private function assertSelectionCount(string $mode, int $count): void
    {
        $normalizedMode = strtolower(trim($mode));

        if ($count < 1) {
            throw new RuntimeException('Select at least one model.');
        }

        if ($normalizedMode === 'single' && $count !== 1) {
            throw new RuntimeException('Single model mode requires exactly one model.');
        }

        if ($normalizedMode === 'compare_two' && $count !== 2) {
            throw new RuntimeException('Compare 2 models mode requires exactly two models.');
        }

        if (in_array($normalizedMode, ['compare_multi', 'compare_multiple'], true) && $count < 2) {
            throw new RuntimeException('Compare multiple mode requires at least two models.');
        }
    }

    private function findLegacyItemForSelection(
        DraftComparison $comparison,
        string $provider,
        string $model,
        int $sortOrder,
        int $estimatedCreditCost,
    ): DraftComparisonItem {
        $existing = DraftComparisonItem::query()
            ->where('draft_comparison_id', $comparison->id)
            ->where('provider', $provider)
            ->where('model', $model)
            ->first();

        if ($existing) {
            if (! in_array((string) $existing->status, ['generated', 'failed'], true)) {
                $existing->status = in_array((string) $existing->status, ['queued', 'generating'], true)
                    ? (string) $existing->status
                    : 'queued';
            }
            if ((int) $existing->sort_order < 1) {
                $existing->sort_order = $sortOrder;
            }
            if ((int) $existing->credit_cost <= 0 && $estimatedCreditCost > 0) {
                $existing->credit_cost = $estimatedCreditCost;
            }
            $existing->save();

            return $existing;
        }

        return DraftComparisonItem::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'draft_comparison_id' => (string) $comparison->id,
            'sort_order' => $sortOrder,
            'provider' => $provider,
            'model' => $model,
            'status' => 'queued',
            'credit_cost' => max(0, $estimatedCreditCost),
        ]);
    }
}
