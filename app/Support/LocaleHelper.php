<?php

namespace App\Support;

use App\Enums\SupportedLanguage;

class LocaleHelper
{
    /**
     * @return array<int, string>
     */
    public static function publicLocales(): array
    {
        return collect((array) config('marketing_routing.locales', ['en', 'nl']))
            ->map(fn (mixed $locale): ?string => SupportedLanguage::tryFromString(is_string($locale) ? $locale : null)?->value)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  iterable<int|string, mixed>  $locales
     * @return array<int, string>
     */
    public static function visibleLocales(iterable $locales): array
    {
        $visible = [];
        $publicLocales = self::publicLocales();

        foreach ($locales as $locale) {
            $resolved = SupportedLanguage::tryFromString(is_string($locale) ? $locale : null)?->value;

            if ($resolved === null || ! in_array($resolved, $publicLocales, true) || in_array($resolved, $visible, true)) {
                continue;
            }

            $visible[] = $resolved;
        }

        return $visible;
    }

    /**
     * @param  iterable<string, mixed>  $localeUrls
     * @return array<string, string>
     */
    public static function visibleLocaleUrls(iterable $localeUrls): array
    {
        $visible = [];
        $publicLocales = self::publicLocales();

        foreach ($localeUrls as $locale => $url) {
            $resolved = SupportedLanguage::tryFromString((string) $locale)?->value;
            $href = trim((string) $url);

            if ($resolved === null || ! in_array($resolved, $publicLocales, true) || $href === '' || isset($visible[$resolved])) {
                continue;
            }

            $visible[$resolved] = $href;
        }

        return $visible;
    }
}
