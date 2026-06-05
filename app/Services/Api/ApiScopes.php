<?php

namespace App\Services\Api;

final class ApiScopes
{
    public const BRIEFS_READ = 'briefs:read';

    public const BRIEFS_WRITE = 'briefs:write';

    public const DRAFTS_READ = 'drafts:read';

    public const DRAFTS_WRITE = 'drafts:write';

    public const GENERATIONS_READ = 'generations:read';

    public const CONTENT_READ = 'content:read';

    public const CONTENT_WRITE = 'content:write';

    public const CONTENT_PUBLISH = 'content:publish';

    public const TRANSLATIONS_WRITE = 'translations:write';

    public const SEO_AUDITS_WRITE = 'seo_audits:write';

    public const SEO_AUDITS_READ = 'seo_audits:read';

    public const ANALYTICS_WRITE = 'analytics:write';

    public const WEBHOOKS_READ = 'webhooks:read';

    public const WEBHOOKS_WRITE = 'webhooks:write';

    public const USAGE_READ = 'usage:read';

    public const DESTINATIONS_READ = 'destinations:read';

    public const DESTINATIONS_WRITE = 'destinations:write';

    public const API_KEYS_READ = 'api_keys:read';

    public const API_KEYS_WRITE = 'api_keys:write';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::BRIEFS_READ,
            self::BRIEFS_WRITE,
            self::DRAFTS_READ,
            self::DRAFTS_WRITE,
            self::GENERATIONS_READ,
            self::CONTENT_READ,
            self::CONTENT_WRITE,
            self::CONTENT_PUBLISH,
            self::TRANSLATIONS_WRITE,
            self::SEO_AUDITS_WRITE,
            self::SEO_AUDITS_READ,
            self::ANALYTICS_WRITE,
            self::WEBHOOKS_READ,
            self::WEBHOOKS_WRITE,
            self::USAGE_READ,
            self::DESTINATIONS_READ,
            self::DESTINATIONS_WRITE,
            self::API_KEYS_READ,
            self::API_KEYS_WRITE,
        ];
    }
}
