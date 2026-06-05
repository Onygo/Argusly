<?php

namespace App\Services\Content;

use App\Enums\SupportedLanguage;
use App\Models\Content;
use App\Services\Publication\ContentPublicationStateService;
use Illuminate\Support\Collection;

class LocaleContentMapService
{
    public function __construct(
        private readonly ContentPublicationStateService $publicationState,
    ) {}

    public function source(Content $content): Content
    {
        return $content->localizationSource();
    }

    /**
     * @return Collection<int,Content>
     */
    public function family(Content $content): Collection
    {
        $source = $this->source($content);
        $source->loadMissing('localizedVariants.currentVersion', 'localizedVariants.translationSourceContent.currentVersion');

        return $source->normalizedLocalizationFamily();
    }

    /**
     * @return Collection<string,Content>
     */
    public function map(Content $content): Collection
    {
        return $this->family($content)
            ->keyBy(fn (Content $variant): string => $variant->localeCode())
            ->sortKeys();
    }

    public function variantForLocale(Content $content, string $locale, bool $publishedOnly = false): ?Content
    {
        $resolvedLocale = SupportedLanguage::fromStringOrDefault($locale)->value;

        return $this->family($content)
            ->first(function (Content $variant) use ($resolvedLocale, $publishedOnly): bool {
                if ($variant->localeCode() !== $resolvedLocale) {
                    return false;
                }

                if (! $publishedOnly) {
                    return true;
                }

                return $this->publicationState->isPublished($variant);
            });
    }

    /**
     * @return array<int,string>
     */
    public function outdatedLocales(Content $content, bool $uppercase = true): array
    {
        return $this->family($content)
            ->filter(fn (Content $variant): bool => $variant->isTranslationOutdated())
            ->map(function (Content $variant) use ($uppercase): string {
                $locale = $variant->localeCode();

                return $uppercase ? strtoupper($locale) : $locale;
            })
            ->values()
            ->all();
    }
}
