<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Language;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class ContentLanguageService
{
    public function __construct(private readonly LanguageService $languages) {}

    public function defaultFor(?Brand $brand = null, ?Account $account = null): string
    {
        $account ??= $brand?->account;

        foreach ([$brand?->default_content_language, $account?->default_content_language, 'en'] as $candidate) {
            if (is_string($candidate) && $this->isEnabledForBrand($candidate, $brand)) {
                return $candidate;
            }
        }

        return 'en';
    }

    /**
     * @return Collection<int, Language>
     */
    public function enabledForBrand(?Brand $brand = null): Collection
    {
        $supported = $this->languages->contentLanguages();
        $enabled = $brand?->enabled_content_languages;

        if (! is_array($enabled) || $enabled === []) {
            return $supported;
        }

        return $supported
            ->whereIn('code', $enabled)
            ->values();
    }

    /**
     * @return array<int, string>
     */
    public function enabledCodesForBrand(?Brand $brand = null): array
    {
        return $this->enabledForBrand($brand)->pluck('code')->all();
    }

    public function isEnabledForBrand(string $code, ?Brand $brand = null): bool
    {
        return in_array($code, $this->enabledCodesForBrand($brand), true);
    }

    /**
     * @return array<int, mixed>
     */
    public function validationRules(?Brand $brand = null): array
    {
        return ['required', 'string', Rule::in($this->enabledCodesForBrand($brand))];
    }

    public function validateForBrand(string $code, ?Brand $brand = null): string
    {
        if (! $this->isEnabledForBrand($code, $brand)) {
            throw new InvalidArgumentException("Content language [{$code}] is not enabled for this brand.");
        }

        return $code;
    }

    public function localeForLanguage(string $code): string
    {
        return match ($code) {
            'nl' => 'nl_NL',
            'de' => 'de_DE',
            'fr' => 'fr_FR',
            'es' => 'es_ES',
            default => 'en_US',
        };
    }
}
