<?php

namespace App\Services;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Cache;

class SiteSettingsService
{
    private const CACHE_PREFIX = 'site_settings:';
    private const CACHE_TTL = 3600; // 1 hour

    public function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember(
            self::CACHE_PREFIX . $key,
            self::CACHE_TTL,
            fn () => SiteSetting::query()->where('key', $key)->first()?->value ?? $default
        );
    }

    public function put(string $key, mixed $value): void
    {
        SiteSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);

        Cache::forget(self::CACHE_PREFIX . $key);
    }

    public function forget(string $key): void
    {
        SiteSetting::query()->where('key', $key)->delete();

        Cache::forget(self::CACHE_PREFIX . $key);
    }

    public function clearCache(string $key): void
    {
        Cache::forget(self::CACHE_PREFIX . $key);
    }
}
