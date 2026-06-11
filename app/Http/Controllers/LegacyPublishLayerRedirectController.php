<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LegacyPublishLayerRedirectController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless((bool) config('legacy_publishlayer.enabled', true), 404);

        $host = strtolower($request->getHost());
        abort_unless(in_array($host, (array) config('legacy_publishlayer.source_hosts', []), true), 404);

        $target = $this->targetUrl($this->targetPath($request), $request);
        $status = in_array($request->getMethod(), ['GET', 'HEAD'], true) ? 301 : 308;

        return redirect()->away($target, $status);
    }

    private function targetPath(Request $request): string
    {
        $path = $this->normalizePath($request->getPathInfo());
        $exact = (array) config('legacy_publishlayer.exact_paths', []);
        $lowerPath = strtolower($path);

        foreach ($exact as $source => $target) {
            if (strtolower($this->normalizePath((string) $source)) === $lowerPath) {
                return $this->normalizeTargetPath((string) $target);
            }
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $segment): bool => $segment !== ''));
        $locales = (array) config('legacy_publishlayer.locales', ['en', 'nl']);
        $defaultLocale = (string) config('legacy_publishlayer.default_locale', 'en');

        if ($segments === []) {
            return "/{$defaultLocale}/";
        }

        $locale = strtolower($segments[0]);
        if (in_array($locale, $locales, true)) {
            $relative = implode('/', array_slice($segments, 1));

            if ($relative === '') {
                return "/{$locale}/";
            }

            return '/' . $locale . '/' . $this->applyLocalizedAlias($locale, $relative);
        }

        return '/' . $defaultLocale . '/' . implode('/', $segments);
    }

    private function applyLocalizedAlias(string $locale, string $relativePath): string
    {
        $aliases = (array) config("legacy_publishlayer.localized_aliases.{$locale}", []);
        $lowerRelative = strtolower($relativePath);

        $bestSource = null;
        $bestTarget = null;

        foreach ($aliases as $source => $target) {
            $source = trim((string) $source, '/');
            $lowerSource = strtolower($source);

            if ($lowerRelative === $lowerSource || Str::startsWith($lowerRelative, $lowerSource . '/')) {
                if ($bestSource === null || strlen($source) > strlen($bestSource)) {
                    $bestSource = $source;
                    $bestTarget = trim((string) $target, '/');
                }
            }
        }

        if ($bestSource === null || $bestTarget === null) {
            return $relativePath;
        }

        $suffix = substr($relativePath, strlen($bestSource));

        if ($suffix === '' || str_starts_with($suffix, '/')) {
            return $bestTarget . $suffix;
        }

        return $relativePath;
    }

    private function targetUrl(string $targetPath, Request $request): string
    {
        $baseUrl = rtrim((string) config('legacy_publishlayer.target_base_url', 'https://argusly.com'), '/');
        $fragment = '';

        if (str_contains($targetPath, '#')) {
            [$targetPath, $fragment] = explode('#', $targetPath, 2);
            $fragment = '#' . $fragment;
        }

        $query = $request->getQueryString();

        return $baseUrl . $this->normalizeTargetPath($targetPath) . ($query ? '?' . $query : '') . $fragment;
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private function normalizeTargetPath(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        if (str_contains($path, '#')) {
            [$path, $fragment] = explode('#', $path, 2);

            return $this->normalizeTargetPath($path) . '#' . $fragment;
        }

        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
