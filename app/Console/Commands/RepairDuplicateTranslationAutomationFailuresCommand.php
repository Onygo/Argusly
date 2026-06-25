<?php

namespace App\Console\Commands;

use App\Enums\SupportedLanguage;
use App\Models\Content;
use App\Models\ContentAutomationRun;
use App\Models\ContentAutomationRunItem;
use App\Services\ContentAutomation\AutomationRunItemStateService;
use Illuminate\Console\Command;

class RepairDuplicateTranslationAutomationFailuresCommand extends Command
{
    protected $signature = 'automations:repair-duplicate-translation-failures
        {--run-id= : Repair a specific automation run}
        {--automation-id= : Repair runs for a specific automation}
        {--locale= : Limit to one target locale, for example nl}
        {--limit=500 : Maximum runs to scan}
        {--allow-shared-target : Allow one existing translation content record to be attached to multiple different source items}
        {--apply : Apply the repair. Without this option the command is a dry run}';

    protected $description = 'Repair automation runs failed by duplicate translation target errors when the translated content already exists.';

    public function handle(AutomationRunItemStateService $stateService): int
    {
        $runId = trim((string) $this->option('run-id'));
        $automationId = trim((string) $this->option('automation-id'));
        $locale = trim((string) $this->option('locale'));
        $apply = (bool) $this->option('apply');
        $allowSharedTarget = (bool) $this->option('allow-shared-target');
        $limit = max(1, (int) $this->option('limit'));

        $runs = ContentAutomationRun::query()
            ->with(['items.sourceItem.content.localizedVariants', 'items.content', 'automation'])
            ->when($runId !== '', fn ($query) => $query->whereKey($runId))
            ->when($automationId !== '', fn ($query) => $query->where('automation_id', $automationId))
            ->where(function ($query): void {
                $query
                    ->where('error_message', 'like', "%already exists for this draft%")
                    ->orWhere('error_message', 'like', "%already processing%")
                    ->orWhereHas('items', function ($itemQuery): void {
                        $itemQuery
                            ->where('item_type', ContentAutomationRunItem::TYPE_TRANSLATION)
                            ->where(function ($errorQuery): void {
                                $errorQuery
                                    ->where('last_error_message', 'like', "%already exists for this draft%")
                                    ->orWhere('last_error_message', 'like', "%already processing%");
                            });
                    });
            })
            ->latest('created_at')
            ->limit($runId !== '' ? 1 : $limit)
            ->get();

        $report = [
            'scanned_runs' => $runs->count(),
            'candidate_items' => 0,
            'repaired_items' => 0,
            'missing_existing_variant' => 0,
            'shared_target_blocked' => 0,
            'synced_runs' => 0,
        ];

        foreach ($runs as $run) {
            $runChanged = false;
            $resolvedItems = [];

            foreach ($this->candidateItems($run, $locale) as $item) {
                $report['candidate_items']++;
                $existingVariant = $this->existingVariantForItem($item);

                if (! $existingVariant instanceof Content) {
                    $report['missing_existing_variant']++;
                    $this->warn(sprintf(
                        '- %s item %s locale=%s no existing variant found',
                        (string) $run->id,
                        (string) $item->id,
                        (string) $item->locale,
                    ));
                    continue;
                }

                $resolvedItems[] = [
                    'item' => $item,
                    'existing_variant' => $existingVariant,
                    'source_key' => trim((string) ($item->source_run_item_id ?? '')) ?: 'item:' . (string) $item->id,
                ];
            }

            $sourceKeysByVariant = collect($resolvedItems)
                ->groupBy(fn (array $resolved): string => (string) $resolved['existing_variant']->id)
                ->map(fn ($items) => $items->pluck('source_key')->unique()->values());

            foreach ($resolvedItems as $resolved) {
                /** @var ContentAutomationRunItem $item */
                $item = $resolved['item'];
                /** @var Content $existingVariant */
                $existingVariant = $resolved['existing_variant'];
                $variantId = (string) $existingVariant->id;
                $sharedAcrossSources = ($sourceKeysByVariant->get($variantId)?->count() ?? 0) > 1;
                $targetReferencesItemSource = $this->targetReferencesItemSource($item, $existingVariant);

                if ($sharedAcrossSources && ! $allowSharedTarget && ! $targetReferencesItemSource) {
                    $report['shared_target_blocked']++;
                    $this->warn(sprintf(
                        '- %s item %s source_content=%s locale=%s shared target %s blocked; target source is %s',
                        (string) $run->id,
                        (string) $item->id,
                        (string) ($item->sourceItem?->content_id ?? ''),
                        (string) $item->locale,
                        $variantId,
                        (string) ($existingVariant->translation_source_content_id ?? ''),
                    ));
                    continue;
                }

                $this->line(sprintf(
                    '- %s item %s source_item=%s source_content=%s locale=%s -> reuse content %s%s',
                    (string) $run->id,
                    (string) $item->id,
                    (string) ($item->source_run_item_id ?? ''),
                    (string) ($item->sourceItem?->content_id ?? ''),
                    (string) $item->locale,
                    $variantId,
                    $sharedAcrossSources && $targetReferencesItemSource ? ' (matched target source)' : '',
                ));

                if ($apply) {
                    $this->repairItem($item, $existingVariant);
                }

                $report['repaired_items']++;
                $runChanged = true;
            }

            if ($runChanged) {
                if ($apply) {
                    $stateService->syncRun($run->fresh(['items', 'automation']) ?? $run);
                }

                $report['synced_runs']++;
            }
        }

        $this->newLine();
        $this->info($apply ? 'Applied duplicate translation automation repair.' : 'Dry run only. Re-run with --apply to write changes.');
        $this->table(
            ['Metric', 'Count'],
            collect($report)->map(fn ($value, string $key): array => [$key, (string) $value])->values()->all()
        );

        return self::SUCCESS;
    }

    /**
     * @return iterable<int,ContentAutomationRunItem>
     */
    private function candidateItems(ContentAutomationRun $run, string $locale): iterable
    {
        $targetLocale = $locale !== '' ? SupportedLanguage::fromStringOrDefault($locale)->value : '';

        return $run->items
            ->filter(fn (ContentAutomationRunItem $item): bool => $item->item_type === ContentAutomationRunItem::TYPE_TRANSLATION)
            ->filter(fn (ContentAutomationRunItem $item): bool => $targetLocale === '' || $item->locale === $targetLocale)
            ->filter(fn (ContentAutomationRunItem $item): bool => $this->isDuplicateTranslationFailure((string) $item->last_error_message));
    }

    private function existingVariantForItem(ContentAutomationRunItem $item): ?Content
    {
        $sourceContent = $item->sourceItem?->content;

        if (! $sourceContent instanceof Content) {
            return null;
        }

        return $sourceContent->localizedVariantFor((string) $item->locale);
    }

    private function repairItem(ContentAutomationRunItem $item, Content $existingVariant): void
    {
        $existingVariant->loadMissing(['drafts' => fn ($query) => $query->latest('created_at'), 'publications']);
        $latestDraft = $existingVariant->drafts->sortByDesc('created_at')->first();
        $metadata = is_array($item->metadata) ? $item->metadata : [];
        $metadata['duplicate_translation_repair'] = [
            'repaired_at' => now()->toIso8601String(),
            'previous_last_error_code' => $item->last_error_code,
            'previous_last_error_message' => $item->last_error_message,
            'existing_variant_id' => (string) $existingVariant->id,
            'command' => 'automations:repair-duplicate-translation-failures',
        ];

        $item->forceFill([
            'status' => ContentAutomationRunItem::STATUS_COMPLETED,
            'failure_stage' => null,
            'last_error_code' => null,
            'last_error_message' => null,
            'content_id' => (string) $existingVariant->id,
            'draft_id' => $latestDraft?->id,
            'content_family_id' => $existingVariant->localizationRootId(),
            'locale' => $existingVariant->localeCode(),
            'source_locale' => $existingVariant->translation_source_locale ?: (string) $item->source_locale,
            'is_source_locale' => false,
            'generation_status' => 'completed',
            'translation_status' => 'completed',
            'delivery_status' => $existingVariant->delivery_status,
            'publication_status' => $existingVariant->publish_status ?: 'draft',
            'metadata' => $metadata,
            'finished_at' => $item->finished_at ?: now(),
        ])->save();
    }

    private function targetReferencesItemSource(ContentAutomationRunItem $item, Content $existingVariant): bool
    {
        $sourceContentId = trim((string) ($item->sourceItem?->content_id ?? ''));
        $targetSourceContentId = trim((string) ($existingVariant->translation_source_content_id ?? ''));

        return $sourceContentId !== '' && $targetSourceContentId !== '' && $sourceContentId === $targetSourceContentId;
    }

    private function isDuplicateTranslationFailure(string $message): bool
    {
        $message = strtolower($message);

        return str_contains($message, 'already exists for this draft')
            || str_contains($message, 'already processing');
    }
}
