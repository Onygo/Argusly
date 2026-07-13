<?php

namespace App\Services\Content;

use App\Enums\SupportedLanguage;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentSeries;

class SeriesLocaleResolver
{
    /**
     * @param  array<string,mixed>  $article
     */
    public function resolve(
        ContentSeries $series,
        ?ClientSite $site = null,
        array $article = [],
        ?Content $content = null,
        ?string $explicitLocale = null,
    ): SupportedLanguage {
        $site ??= $series->site;

        foreach ($this->candidates($series, $site, $article, $content, $explicitLocale) as $candidate) {
            $language = SupportedLanguage::tryFromString(is_string($candidate) ? $candidate : (string) $candidate);
            if ($language instanceof SupportedLanguage) {
                return $language;
            }
        }

        return SupportedLanguage::default();
    }

    /**
     * @param  array<string,mixed>  $article
     */
    public function resolveCode(
        ContentSeries $series,
        ?ClientSite $site = null,
        array $article = [],
        ?Content $content = null,
        ?string $explicitLocale = null,
    ): string {
        return $this->resolve($series, $site, $article, $content, $explicitLocale)->value;
    }

    public function promptLabel(SupportedLanguage|string|null $language): string
    {
        $resolved = $language instanceof SupportedLanguage
            ? $language
            : (SupportedLanguage::tryFromString((string) $language) ?? SupportedLanguage::default());

        return $resolved->englishLabel() . ' (' . $resolved->value . ')';
    }

    /**
     * @param  array<string,mixed>  $article
     * @return array<int,mixed>
     */
    private function candidates(
        ContentSeries $series,
        ?ClientSite $site,
        array $article,
        ?Content $content,
        ?string $explicitLocale,
    ): array {
        return [
            $explicitLocale,
            $content?->localeCode(),
            data_get($article, 'language'),
            data_get($article, 'locale'),
            data_get($article, 'target_locale'),
            data_get($series->strategy_json, 'meta.chain_settings.language'),
            data_get($series->strategy_json, 'meta.language'),
            data_get($series->strategy_json, 'meta.target_language'),
            data_get($series->strategy_json, 'meta.target_locale'),
            data_get($series->strategy_json, 'meta.source_language'),
            data_get($series->strategy_json, 'meta.complete_briefing.derived.language'),
            $site?->workspace?->defaultContentLanguageCode(),
            $series->site?->workspace?->defaultContentLanguageCode(),
        ];
    }
}
