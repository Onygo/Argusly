<?php

namespace App\Services;

use App\Enums\SupportedLanguage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class LanguageResolverService
{
    private const PLATFORM_UI_LOCALES = ['en', 'nl'];
    private const SESSION_KEY_PUBLIC = 'public_locale';
    private const SESSION_KEY_APP = 'app_locale';
    private const COOKIE_KEY = 'pl_locale';

    public function resolveUiLocale(Request $request, string $context = 'public'): string
    {
        $explicit = $this->getExplicitChoice($request, $context);
        if ($explicit && $this->isPlatformUiLocale($explicit)) {
            return $explicit;
        }

        if (auth()->check()) {
            $userLocale = $this->getUserPreferredLocale(auth()->user());
            if ($userLocale && $this->isPlatformUiLocale($userLocale)) {
                return $userLocale;
            }
        }

        $browser = $this->detectBrowserLocale($request);
        if ($browser && $this->isPlatformUiLocale($browser)) {
            return $browser;
        }

        return SupportedLanguage::platformDefault()->value;
    }

    public function resolvePublicLocale(Request $request): string
    {
        $queryLang = $request->query('lang');
        if (is_string($queryLang) && $this->isPlatformUiLocale($queryLang)) {
            return strtolower($queryLang);
        }

        $cookieLang = $request->cookie(self::COOKIE_KEY);
        if (is_string($cookieLang) && $this->isPlatformUiLocale($cookieLang)) {
            return strtolower($cookieLang);
        }

        $browser = $this->detectBrowserLocale($request);
        if ($browser && $this->isPlatformUiLocale($browser)) {
            return $browser;
        }

        return SupportedLanguage::platformDefault()->value;
    }

    public function resolveAppLocale(Request $request): string
    {
        return $this->resolveUiLocale($request, 'app');
    }

    public function setExplicitChoice(Request $request, string $locale, string $context = 'public'): void
    {
        if (! $this->isPlatformUiLocale($locale)) {
            $locale = SupportedLanguage::platformDefault()->value;
        }

        $sessionKey = $context === 'app' ? self::SESSION_KEY_APP : self::SESSION_KEY_PUBLIC;
        Session::put($sessionKey, $locale);

        if ($context === 'app' && auth()->check()) {
            $user = auth()->user();
            if ($user instanceof User && method_exists($user, 'setPreferredLocale')) {
                $user->setPreferredLocale($locale);
            }
        }
    }

    public function clearExplicitChoice(string $context = 'public'): void
    {
        $sessionKey = $context === 'app' ? self::SESSION_KEY_APP : self::SESSION_KEY_PUBLIC;
        Session::forget($sessionKey);
    }

    public function detectBrowserLocale(Request $request): ?string
    {
        $acceptLanguage = $request->header('Accept-Language', '');

        if (empty($acceptLanguage)) {
            return null;
        }

        $parts = explode(',', $acceptLanguage);
        if (empty($parts)) {
            return null;
        }

        foreach ($parts as $part) {
            $langPart = explode(';', trim($part))[0];
            $code = strtolower(substr(trim($langPart), 0, 2));

            if ($this->isPlatformUiLocale($code)) {
                return $code;
            }
        }

        return null;
    }

    public function isPlatformUiLocale(string $locale): bool
    {
        return in_array(strtolower($locale), self::PLATFORM_UI_LOCALES, true);
    }

    public function getPlatformUiLocales(): array
    {
        return self::PLATFORM_UI_LOCALES;
    }

    public function getContentLanguages(): array
    {
        return SupportedLanguage::values();
    }

    public function resolveContentLanguage(?string $languageCode, ?SupportedLanguage $default = null): SupportedLanguage
    {
        if ($languageCode) {
            $language = SupportedLanguage::tryFrom(strtolower($languageCode));
            if ($language) {
                return $language;
            }
        }

        return $default ?? SupportedLanguage::default();
    }

    private function getExplicitChoice(Request $request, string $context): ?string
    {
        $queryLang = $request->query('lang');
        if (is_string($queryLang) && $this->isPlatformUiLocale($queryLang)) {
            return strtolower($queryLang);
        }

        $sessionKey = $context === 'app' ? self::SESSION_KEY_APP : self::SESSION_KEY_PUBLIC;
        $sessionLang = Session::get($sessionKey);
        if (is_string($sessionLang) && $this->isPlatformUiLocale($sessionLang)) {
            return strtolower($sessionLang);
        }

        $cookieLang = $request->cookie(self::COOKIE_KEY);
        if (is_string($cookieLang) && $this->isPlatformUiLocale($cookieLang)) {
            return strtolower($cookieLang);
        }

        return null;
    }

    private function getUserPreferredLocale(?object $user): ?string
    {
        if (! $user) {
            return null;
        }

        if (property_exists($user, 'preferred_locale') || (method_exists($user, 'getAttribute') && $user->getAttribute('preferred_locale'))) {
            $locale = $user->preferred_locale ?? null;
            if (is_string($locale) && $locale !== '') {
                return strtolower($locale);
            }
        }

        return null;
    }
}
