<?php

namespace App\Support\Interaction;

final class DrawerHistoryAdapter
{
    public function metadata(DrawerTarget $target, array $overrides = []): array
    {
        $fallbackUrl = $target->fallbackUrl();
        $drawerUrl = $this->drawerUrl($target, $fallbackUrl);

        return array_replace_recursive([
            'strategy' => 'query',
            'push' => false,
            'replace' => false,
            'fallback_url' => $fallbackUrl,
            'drawer_url' => $drawerUrl,
            'parameters' => array_filter([
                'drawer' => $target->target,
                'drawer_mode' => $target->mode,
                'drawer_resource' => $target->resourceKey,
                'drawer_resource_id' => $target->resourceId,
                'drawer_action' => $target->actionKey,
            ], fn (mixed $value): bool => $value !== null && $value !== ''),
        ], $overrides);
    }

    public function drawerUrl(DrawerTarget $target, ?string $fallbackUrl = null): string
    {
        $fallbackUrl ??= $target->fallbackUrl();

        if ($fallbackUrl === '#') {
            return '#';
        }

        $parts = parse_url($fallbackUrl);
        $query = [];

        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        $query = array_replace($query, array_filter([
            'drawer' => $target->target,
            'drawer_mode' => $target->mode,
            'drawer_resource' => $target->resourceKey,
            'drawer_resource_id' => $target->resourceId,
            'drawer_action' => $target->actionKey,
        ], fn (mixed $value): bool => $value !== null && $value !== ''));

        $path = ($parts['path'] ?? '') === '' ? '/' : $parts['path'];
        $scheme = isset($parts['scheme']) ? $parts['scheme'].'://' : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return $scheme.$host.$port.$path.'?'.http_build_query($query).$fragment;
    }
}
