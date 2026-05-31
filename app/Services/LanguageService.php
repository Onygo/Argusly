<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Language;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class LanguageService
{
    /**
     * @return Collection<int, Language>
     */
    public function uiLanguages(): Collection
    {
        return $this->languages()
            ->where('is_ui_enabled', true)
            ->values();
    }

    /**
     * @return Collection<int, Language>
     */
    public function contentLanguages(): Collection
    {
        return $this->languages()
            ->where('is_content_enabled', true)
            ->values();
    }

    /**
     * @return array<int, string>
     */
    public function uiCodes(): array
    {
        return $this->uiLanguages()->pluck('code')->all();
    }

    /**
     * @return array<int, string>
     */
    public function contentCodes(): array
    {
        return $this->contentLanguages()->pluck('code')->all();
    }

    public function defaultCode(): string
    {
        return $this->languages()->firstWhere('is_default', true)?->code
            ?? config('app.fallback_locale', 'en');
    }

    public function isUiLocale(string $code): bool
    {
        return in_array($code, $this->uiCodes(), true);
    }

    public function isContentLanguage(string $code): bool
    {
        return in_array($code, $this->contentCodes(), true);
    }

    public function resolveUiLocale(?User $user = null, ?Account $account = null): string
    {
        $fallback = config('app.fallback_locale', 'en');

        foreach ([$user?->locale, $account?->default_locale, $fallback] as $candidate) {
            if (is_string($candidate) && $this->isUiLocale($candidate)) {
                return $candidate;
            }
        }

        return 'en';
    }

    /**
     * @return Collection<int, Language>
     */
    private function languages(): Collection
    {
        if (! Schema::hasTable('languages') || ! Language::query()->exists()) {
            return collect($this->fallbackLanguages())
                ->map(fn (array $language) => new Language($language))
                ->sortBy('sort_order')
                ->values();
        }

        return Language::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fallbackLanguages(): array
    {
        return [
            ['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'is_ui_enabled' => true, 'is_content_enabled' => true, 'is_default' => true, 'sort_order' => 10],
            ['code' => 'nl', 'name' => 'Dutch', 'native_name' => 'Nederlands', 'is_ui_enabled' => true, 'is_content_enabled' => true, 'is_default' => false, 'sort_order' => 20],
            ['code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch', 'is_ui_enabled' => false, 'is_content_enabled' => true, 'is_default' => false, 'sort_order' => 30],
            ['code' => 'fr', 'name' => 'French', 'native_name' => 'Français', 'is_ui_enabled' => false, 'is_content_enabled' => true, 'is_default' => false, 'sort_order' => 40],
            ['code' => 'es', 'name' => 'Spanish', 'native_name' => 'Español', 'is_ui_enabled' => false, 'is_content_enabled' => true, 'is_default' => false, 'sort_order' => 50],
        ];
    }
}
