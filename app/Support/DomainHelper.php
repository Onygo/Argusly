<?php

namespace App\Support;

use Illuminate\Support\Facades\Request;

class DomainHelper
{
    /**
     * Get the full URL for a subdomain path.
     */
    public static function url(string $subdomain, string $path = '/'): string
    {
        $host = self::host($subdomain);
        $scheme = self::scheme();
        $path = '/' . ltrim($path, '/');

        return "{$scheme}://{$host}{$path}";
    }

    /**
     * Get the host for a subdomain.
     */
    public static function host(string $subdomain): string
    {
        $baseDomain = config('domains.base', 'argusly.local');
        $subdomainConfig = config("domains.subdomains.{$subdomain}");

        if (! $subdomainConfig) {
            throw new \InvalidArgumentException("Unknown subdomain: {$subdomain}");
        }

        $prefix = $subdomainConfig['prefix'] ?? '';

        return $prefix !== '' ? "{$prefix}.{$baseDomain}" : $baseDomain;
    }

    /**
     * Get the scheme (http or https) based on current request or config.
     */
    public static function scheme(): string
    {
        if (app()->runningInConsole()) {
            return config('app.url') && str_starts_with(config('app.url'), 'https') ? 'https' : 'http';
        }

        return Request::secure() ? 'https' : 'http';
    }

    /**
     * Check if current request is on a specific subdomain.
     */
    public static function isSubdomain(string $subdomain): bool
    {
        if (app()->runningInConsole()) {
            return false;
        }

        return Request::getHost() === self::host($subdomain);
    }

    /**
     * Get the current subdomain name.
     */
    public static function currentSubdomain(): ?string
    {
        if (app()->runningInConsole()) {
            return null;
        }

        $currentHost = Request::getHost();

        foreach (array_keys(config('domains.subdomains', [])) as $name) {
            if ($currentHost === self::host($name)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Check if the current host is in the excluded hosts list.
     */
    public static function isExcludedHost(?string $host = null): bool
    {
        $host = $host ?? (app()->runningInConsole() ? '' : Request::getHost());
        $excludedHosts = config('domains.excluded_hosts', []);

        return in_array($host, $excludedHosts, true);
    }

    /**
     * Get the base domain.
     */
    public static function baseDomain(): string
    {
        return config('domains.base', 'argusly.local');
    }
}

/*
|--------------------------------------------------------------------------
| Global Helper Function
|--------------------------------------------------------------------------
*/

if (! function_exists('domain_url')) {
    /**
     * Generate a URL for a specific subdomain.
     *
     * @param  string  $subdomain  The subdomain name (marketing, app, admin, api)
     * @param  string  $path  The path to append
     * @return string
     */
    function domain_url(string $subdomain, string $path = '/'): string
    {
        return DomainHelper::url($subdomain, $path);
    }
}
