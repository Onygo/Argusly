<?php

namespace App\Services\ContentAutomation;

use App\Enums\SupportedLanguage;
use App\Enums\ContentAutomationMode;
use App\Models\ContentAutomation;

class AutomationLocaleResolver
{
    public function sourceLocale(ContentAutomation $automation): string
    {
        return $automation->sourceLocale();
    }

    /**
     * @return array<int, string>
     */
    public function configuredLocales(ContentAutomation $automation): array
    {
        if ($this->isChainedAutomation($automation)) {
            return $automation->configuredLocales();
        }

        $locales = collect($automation->configuredLocales())
            ->filter(function (string $locale) use ($automation): bool {
                $workspace = $automation->workspace;
                if (! $workspace) {
                    return true;
                }

                return $workspace->isLanguageEnabled(SupportedLanguage::fromStringOrDefault($locale));
            })
            ->unique()
            ->values()
            ->all();

        return $locales === [] ? [$this->sourceLocale($automation)] : $locales;
    }

    /**
     * @return array<int, string>
     */
    public function targetLocales(ContentAutomation $automation): array
    {
        $sourceLocale = $this->sourceLocale($automation);

        return collect($this->configuredLocales($automation))
            ->reject(fn (string $locale): bool => $locale === $sourceLocale)
            ->values()
            ->all();
    }

    public function shouldTranslate(ContentAutomation $automation): bool
    {
        return ($automation->autoTranslateGeneratedContent() || $this->isChainedAutomation($automation))
            && $this->targetLocales($automation) !== [];
    }

    private function isChainedAutomation(ContentAutomation $automation): bool
    {
        $mode = $automation->mode instanceof ContentAutomationMode
            ? $automation->mode
            : ContentAutomationMode::tryFrom((string) $automation->mode);

        return in_array($mode, [ContentAutomationMode::CHAIN, ContentAutomationMode::PILLAR_PLUS_CLUSTER], true);
    }

    /**
     * @param  array<int, array<string, mixed>>  $articles
     * @return array<int, array<string, mixed>>
     */
    public function buildResultBlueprints(ContentAutomation $automation, array $articles): array
    {
        $sourceLocale = $this->sourceLocale($automation);
        $targetLocales = $this->shouldTranslate($automation)
            ? $this->targetLocales($automation)
            : [];

        $blueprints = [];

        foreach ($articles as $index => $articlePlan) {
            $sourceKey = (string) ($articlePlan['stable_key'] ?? $articlePlan['sequence'] ?? ($index + 1));
            $sequence = (int) ($articlePlan['sequence'] ?? ($index + 1));
            $title = (string) ($articlePlan['title'] ?? '');

            $blueprints[] = [
                'source_key' => $sourceKey,
                'source_sequence' => $sequence,
                'chain_index' => $sequence,
                'item_type' => 'source',
                'locale' => $sourceLocale,
                'source_locale' => $sourceLocale,
                'is_source_locale' => true,
                'title' => $title,
                'plan' => $articlePlan,
            ];

            foreach ($targetLocales as $offset => $locale) {
                $blueprints[] = [
                    'source_key' => $sourceKey,
                    'source_sequence' => $sequence,
                    'chain_index' => ($sequence * 100) + ($offset + 1),
                    'item_type' => 'translation',
                    'locale' => $locale,
                    'source_locale' => $sourceLocale,
                    'is_source_locale' => false,
                    'title' => $title,
                    'plan' => $articlePlan,
                ];
            }
        }

        return $blueprints;
    }
}
