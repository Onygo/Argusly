<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SocialImageResolver
{
    public function resolve(?string $explicitImage = null, ?string $canonicalUrl = null, ?string $pageType = null, ?Request $request = null): string
    {
        $request ??= request();

        foreach ([$explicitImage, $this->mappedImage($pageType, $request), $this->deterministicFallback($canonicalUrl, $request), $this->defaultImage()] as $candidate) {
            $url = $this->absoluteUrl((string) $candidate);

            if ($url !== '') {
                return $url;
            }
        }

        return asset(ltrim((string) config('argusly_social.default_image'), '/'));
    }

    public function deterministicFallback(?string $canonicalUrl = null, ?Request $request = null): string
    {
        $request ??= request();
        $variants = array_values(array_filter((array) config('argusly_social.variants', []), fn ($variant): bool => is_string($variant) && trim($variant) !== ''));

        if ($variants === []) {
            return (string) config('argusly_social.default_image', '');
        }

        $routeName = $this->normalizedRouteName($request);
        $key = trim((string) $canonicalUrl) !== '' ? trim((string) $canonicalUrl) : ($routeName !== '' ? $routeName : $request->url());

        return $variants[abs(crc32($key)) % count($variants)];
    }

    private function mappedImage(?string $pageType, Request $request): string
    {
        $pageType = trim((string) $pageType);
        $pageTypeMappings = (array) config('argusly_social.page_type_mapping', []);

        if ($pageType !== '' && is_string($pageTypeMappings[$pageType] ?? null)) {
            return (string) $pageTypeMappings[$pageType];
        }

        $routeName = $this->normalizedRouteName($request);
        $routeMappings = (array) config('argusly_social.route_type_mapping', []);

        foreach ($routeMappings as $pattern => $image) {
            if (is_string($pattern) && is_string($image) && Str::is($pattern, $routeName)) {
                return $image;
            }
        }

        return '';
    }

    private function defaultImage(): string
    {
        return (string) config('argusly_social.default_image', '');
    }

    private function normalizedRouteName(Request $request): string
    {
        $routeName = (string) ($request->route()?->getName() ?? '');

        return Str::startsWith($routeName, 'test.') ? Str::after($routeName, 'test.') : $routeName;
    }

    private function absoluteUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        if (Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        return asset(ltrim($url, '/'));
    }
}
