<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

class MarketingRouteSegments
{
    /**
     * @var array<string, string>
     */
    private const ROUTE_ALIASES = [
        'public.contact' => 'public.company.contact',
        'public.contact.submit' => 'public.company.contact.submit',
    ];

    public function locales(): array
    {
        return array_values(array_filter(
            array_map(
                fn (mixed $locale): string => strtolower(trim((string) $locale)),
                (array) config('marketing_routing.locales', ['en', 'nl'])
            )
        ));
    }

    public function defaultLocale(): string
    {
        return $this->resolveLocale((string) config('marketing_routing.default_locale', 'en'));
    }

    public function resolveLocale(?string $locale): string
    {
        $resolved = strtolower(trim((string) $locale));

        if ($resolved !== '' && in_array($resolved, $this->locales(), true)) {
            return $resolved;
        }

        if (app()->bound('request')) {
            $appLocale = strtolower(trim((string) app()->getLocale()));
            if ($appLocale !== '' && in_array($appLocale, $this->locales(), true)) {
                return $appLocale;
            }
        }

        return strtolower((string) config('marketing_routing.default_locale', 'en'));
    }

    public function isSupportedLocale(?string $locale): bool
    {
        return in_array(strtolower(trim((string) $locale)), $this->locales(), true);
    }

    public function segment(string $key, ?string $locale = null): string
    {
        $locale = $this->resolveLocale($locale);
        $segments = (array) config('marketing_routing.segments', []);
        $values = (array) ($segments[$key] ?? []);
        $value = trim((string) ($values[$locale] ?? ''));

        if ($value !== '') {
            return $value;
        }

        $message = sprintf('Missing marketing route segment [%s] for locale [%s].', $key, $locale);

        if (app()->environment(['local', 'testing'])) {
            throw new \RuntimeException($message);
        }

        return trim((string) ($values[$this->defaultLocale()] ?? $key));
    }

    public function canonicalRouteName(string $routeName): string
    {
        $routeName = trim($routeName);

        return self::ROUTE_ALIASES[$routeName] ?? $routeName;
    }

    public function localizedRouteName(string $routeName, ?string $locale = null): string
    {
        $canonical = $this->canonicalRouteName($routeName);
        $resolvedLocale = $this->resolveLocale($locale);

        if ($resolvedLocale === $this->defaultLocale()) {
            return $canonical;
        }

        return sprintf('localized.%s.%s', $resolvedLocale, $canonical);
    }

    public function logicalRouteName(?string $routeName): ?string
    {
        $name = trim((string) $routeName);
        if ($name === '') {
            return null;
        }

        if (str_starts_with($name, 'test.')) {
            $name = substr($name, 5);
        }

        if (preg_match('/^localized\.[a-z]{2}\.(.+)$/', $name, $matches) === 1) {
            $name = (string) ($matches[1] ?? '');
        }

        if ($name === '') {
            return null;
        }

        return $this->canonicalRouteName($name);
    }

    public function localizedRouteExists(string $routeName, ?string $locale = null): bool
    {
        return Route::has($this->localizedRouteName($routeName, $locale))
            || Route::has('test.' . $this->localizedRouteName($routeName, $locale));
    }

    public function assertConfigured(): void
    {
        $segments = (array) config('marketing_routing.segments', []);

        foreach ($segments as $key => $translations) {
            foreach ($this->locales() as $locale) {
                $value = trim((string) data_get($translations, $locale));
                if ($value === '') {
                    throw new \RuntimeException(sprintf('Missing marketing routing config for segment [%s] and locale [%s].', $key, $locale));
                }
            }
        }
    }
}
