<?php

namespace App\View\Presenters;

use App\Enums\SupportedLanguage;
use App\Models\Content;
use Illuminate\Support\Collection;

class ContentLocaleOverviewPresenter
{
    /**
     * @param  Collection<int,Content>  $contents
     * @return array<string,array<string,mixed>>
     */
    public static function map(Collection $contents): array
    {
        return $contents
            ->mapWithKeys(fn (Content $content): array => [
                (string) $content->id => self::for($content),
            ])
            ->all();
    }

    /**
     * @return array{badges:array<int,array<string,mixed>>,summary:string}
     */
    public static function for(Content $content): array
    {
        $root = $content->localizationSource();
        $root->loadMissing([
            'workspace',
            'clientSite',
            'contentDestination',
            'publications.destination',
            'localizedVariants.workspace',
            'localizedVariants.clientSite',
            'localizedVariants.contentDestination',
            'localizedVariants.publications.destination',
        ]);
        $variants = $root->normalizedLocalizationFamily();
        $existingLocales = $variants
            ->map(fn (Content $variant): string => $variant->localeCode())
            ->unique()
            ->values();

        $enabledLocales = collect($root->workspace?->getEnabledLanguagesAsEnums() ?? [])
            ->filter(fn ($language): bool => $language instanceof SupportedLanguage)
            ->whenEmpty(fn (Collection $collection) => $collection->push(
                SupportedLanguage::fromStringOrDefault($root->localeCode())
            ))
            ->values();

        $badges = $variants
            ->map(function (Content $variant) use ($root): array {
                $presenter = ContentStatusPresenter::for($variant);
                $status = self::statusMeta($variant, $presenter);
                $isSource = self::isSourceVariant($variant, $root);
                $updatedAt = $variant->updated_at?->format('Y-m-d H:i') ?? 'Unknown';
                $lastSync = $presenter->lastSyncFormatted();

                $tooltipParts = [
                    strtoupper($variant->localeCode()) . ($isSource ? ' source locale' : ' variant'),
                    'Status: ' . $status['label'],
                    'Published: ' . (($status['is_live'] ?? false) ? 'yes' : 'no'),
                    'Updated: ' . $updatedAt,
                ];

                if ($lastSync) {
                    $tooltipParts[] = 'Last sync: ' . $lastSync;
                }

                return [
                    'content' => $variant,
                    'locale' => strtoupper($variant->localeCode()),
                    'status' => $status['key'],
                    'status_label' => $status['label'],
                    'tone' => $status['tone'],
                    'is_source' => $isSource,
                    'is_missing' => false,
                    'tooltip' => implode("\n", $tooltipParts),
                ];
            })
            ->values();

        $missingBadges = $enabledLocales
            ->reject(fn (SupportedLanguage $language): bool => $existingLocales->contains($language->value))
            ->map(fn (SupportedLanguage $language): array => [
                'locale' => strtoupper($language->value),
                'status' => 'missing',
                'status_label' => 'Missing',
                'tone' => 'slate',
                'is_source' => false,
                'is_missing' => true,
                'tooltip' => 'Missing translation for ' . $language->englishLabel(),
            ])
            ->values();

        $liveCount = $badges->filter(fn (array $badge): bool => (bool) ($badge['tone'] === 'green'))->count();
        $queuedCount = $badges->filter(fn (array $badge): bool => (bool) ($badge['tone'] === 'amber'))->count();
        $draftCount = $badges->filter(fn (array $badge): bool => in_array((string) ($badge['tone'] ?? ''), ['slate', 'zinc'], true))->count();
        $missingCount = $missingBadges->count();
        $sourceLocale = $badges->firstWhere('is_source', true)['locale'] ?? strtoupper($root->localeCode());

        $summaryParts = [
            'Source ' . $sourceLocale,
            $liveCount . ' live',
        ];

        if ($queuedCount > 0) {
            $summaryParts[] = $queuedCount . ' queued';
        }

        if ($draftCount > 0) {
            $summaryParts[] = $draftCount . ' draft';
        }

        if ($missingCount > 0) {
            $summaryParts[] = $missingCount . ' missing';
        }

        return [
            'badges' => $badges->concat($missingBadges)->all(),
            'summary' => implode(' | ', $summaryParts),
        ];
    }

    /**
     * @return array{key:string,label:string,tone:string,is_live:bool}
     */
    private static function statusMeta(Content $content, ContentStatusPresenter $presenter): array
    {
        $deliveryStatus = $presenter->deliveryStatus();
        $remotePublishStatus = $presenter->remotePublishStatus()?->value ?? '';
        $lifecycleLabel = strtolower($presenter->lifecycleLabel());

        if ($deliveryStatus->isFailure() || $presenter->needsAttention()) {
            return [
                'key' => 'failed',
                'label' => 'Failed',
                'tone' => 'red',
                'is_live' => false,
            ];
        }

        if ($remotePublishStatus === 'published' || $content->isPublishedForTranslation()) {
            return [
                'key' => 'published',
                'label' => 'Published',
                'tone' => 'green',
                'is_live' => true,
            ];
        }

        if ($deliveryStatus->isSuccess()) {
            return [
                'key' => 'delivered',
                'label' => 'Delivered',
                'tone' => 'green',
                'is_live' => true,
            ];
        }

        if ($deliveryStatus->isInProgress() || in_array($remotePublishStatus, ['scheduled'], true)) {
            return [
                'key' => 'queued',
                'label' => 'Queued',
                'tone' => 'amber',
                'is_live' => false,
            ];
        }

        if (str_contains($lifecycleLabel, 'review')) {
            return [
                'key' => 'in_review',
                'label' => 'In review',
                'tone' => 'slate',
                'is_live' => false,
            ];
        }

        if (str_contains($lifecycleLabel, 'brief')) {
            return [
                'key' => 'brief',
                'label' => 'Brief',
                'tone' => 'slate',
                'is_live' => false,
            ];
        }

        return [
            'key' => 'draft',
            'label' => 'Draft',
            'tone' => 'slate',
            'is_live' => false,
        ];
    }

    private static function isSourceVariant(Content $variant, Content $root): bool
    {
        return ! $variant->isTranslationVariant()
            || (bool) $variant->is_source_locale
            || (string) $variant->id === (string) $root->id;
    }
}
