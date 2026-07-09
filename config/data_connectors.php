<?php

use App\Services\DataConnectors\GoogleAnalytics4\GoogleAnalytics4DatasetDiscoveryAdapter;
use App\Services\DataConnectors\GoogleAnalytics4\GoogleAnalytics4ReportingSyncAdapter;
use App\Services\DataConnectors\GoogleSearchConsole\GoogleSearchConsoleDatasetDiscoveryAdapter;
use App\Services\DataConnectors\GoogleSearchConsole\GoogleSearchConsoleSearchAnalyticsSyncAdapter;
use App\Services\DataConnectors\LinkedIn\LinkedInAnalyticsSyncAdapter;
use App\Services\DataConnectors\LinkedIn\LinkedInDatasetDiscoveryAdapter;

return [
    'oauth' => [
        'state_ttl_minutes' => (int) env('DATA_CONNECTOR_OAUTH_STATE_TTL_MINUTES', 10),
        'http_timeout_seconds' => (int) env('DATA_CONNECTOR_OAUTH_HTTP_TIMEOUT_SECONDS', 15),
    ],

    'health' => [
        'retention' => [
            'enabled' => (bool) env('DATA_CONNECTOR_HEALTH_RETENTION_ENABLED', false),
            'days' => (int) env('DATA_CONNECTOR_HEALTH_RETENTION_DAYS', 180),
        ],
    ],

    'sync' => [
        'stale_running_after_minutes' => (int) env('DATA_CONNECTOR_SYNC_STALE_RUNNING_AFTER_MINUTES', 60),
    ],

    'providers' => [
        'google_search_console' => [
            'provider_key' => 'google_search_console',
            'name' => 'Google Search Console',
            'category' => 'search',
            'status' => 'active',
            'supports_oauth' => true,
            'supports_sync' => true,
            'supports_webhooks' => false,
            'dataset_discovery' => [
                'adapter' => GoogleSearchConsoleDatasetDiscoveryAdapter::class,
            ],
            'sync' => [
                'adapter' => GoogleSearchConsoleSearchAnalyticsSyncAdapter::class,
            ],
            'config_json' => [
                'datasets' => ['sites', 'search_analytics'],
                'required_scopes' => [
                    'https://www.googleapis.com/auth/webmasters.readonly',
                ],
                'oauth' => [
                    'authorization_url' => env('GOOGLE_SEARCH_CONSOLE_AUTHORIZATION_URL', 'https://accounts.google.com/o/oauth2/v2/auth'),
                    'token_url' => env('GOOGLE_SEARCH_CONSOLE_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
                    'revoke_url' => env('GOOGLE_SEARCH_CONSOLE_REVOKE_URL', 'https://oauth2.googleapis.com/revoke'),
                    'client_id' => env('GOOGLE_SEARCH_CONSOLE_CLIENT_ID', 'google-search-console-client-id'),
                    'client_secret' => env('GOOGLE_SEARCH_CONSOLE_CLIENT_SECRET'),
                    'redirect_uri' => env('GOOGLE_SEARCH_CONSOLE_REDIRECT_URI', env('APP_URL', 'http://localhost').'/connectors/oauth/google-search-console/callback'),
                    'scopes' => [
                        'https://www.googleapis.com/auth/webmasters.readonly',
                    ],
                    'authorization_params' => [
                        'access_type' => 'offline',
                        'prompt' => 'consent',
                        'include_granted_scopes' => 'true',
                    ],
                ],
                'api' => [
                    'base_url' => env('GOOGLE_SEARCH_CONSOLE_API_BASE_URL', 'https://www.googleapis.com/webmasters/v3'),
                    'timeout_seconds' => (int) env('GOOGLE_SEARCH_CONSOLE_API_TIMEOUT_SECONDS', 15),
                ],
            ],
        ],
        'google_analytics_4' => [
            'provider_key' => 'google_analytics_4',
            'name' => 'Google Analytics 4',
            'category' => 'analytics',
            'status' => 'active',
            'supports_oauth' => true,
            'supports_sync' => true,
            'supports_webhooks' => false,
            'dataset_discovery' => [
                'adapter' => GoogleAnalytics4DatasetDiscoveryAdapter::class,
            ],
            'sync' => [
                'adapter' => GoogleAnalytics4ReportingSyncAdapter::class,
            ],
            'config_json' => [
                'datasets' => ['accounts', 'properties', 'data_streams', 'reports'],
                'required_scopes' => [
                    'https://www.googleapis.com/auth/analytics.readonly',
                ],
                'oauth' => [
                    'authorization_url' => env('GOOGLE_ANALYTICS_4_AUTHORIZATION_URL', 'https://accounts.google.com/o/oauth2/v2/auth'),
                    'token_url' => env('GOOGLE_ANALYTICS_4_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
                    'revoke_url' => env('GOOGLE_ANALYTICS_4_REVOKE_URL', 'https://oauth2.googleapis.com/revoke'),
                    'client_id' => env('GOOGLE_ANALYTICS_4_CLIENT_ID', 'google-analytics-4-client-id'),
                    'client_secret' => env('GOOGLE_ANALYTICS_4_CLIENT_SECRET'),
                    'redirect_uri' => env('GOOGLE_ANALYTICS_4_REDIRECT_URI', env('APP_URL', 'http://localhost').'/connectors/oauth/google-analytics-4/callback'),
                    'scopes' => [
                        'https://www.googleapis.com/auth/analytics.readonly',
                    ],
                    'authorization_params' => [
                        'access_type' => 'offline',
                        'prompt' => 'consent',
                        'include_granted_scopes' => 'true',
                    ],
                ],
                'api' => [
                    'admin_base_url' => env('GOOGLE_ANALYTICS_4_ADMIN_API_BASE_URL', 'https://analyticsadmin.googleapis.com/v1beta'),
                    'data_base_url' => env('GOOGLE_ANALYTICS_4_DATA_API_BASE_URL', 'https://analyticsdata.googleapis.com/v1beta'),
                    'timeout_seconds' => (int) env('GOOGLE_ANALYTICS_4_API_TIMEOUT_SECONDS', 15),
                    'admin_page_size' => (int) env('GOOGLE_ANALYTICS_4_ADMIN_API_PAGE_SIZE', 200),
                    'report_page_size' => (int) env('GOOGLE_ANALYTICS_4_REPORT_PAGE_SIZE', 10000),
                ],
                'discovery' => [
                    'include_data_streams' => (bool) env('GOOGLE_ANALYTICS_4_DISCOVER_DATA_STREAMS', true),
                ],
                'sync' => [
                    'metrics' => [
                        'sessions',
                        'users',
                        'newUsers',
                        'engagedSessions',
                        'engagementRate',
                        'averageSessionDuration',
                        'eventCount',
                        'keyEvents',
                    ],
                    'dimensions' => [
                        'date',
                        'pagePath',
                        'sessionSource',
                        'sessionMedium',
                        'sessionCampaign',
                        'deviceCategory',
                        'country',
                        'defaultChannelGroup',
                    ],
                ],
            ],
        ],
        'linkedin' => [
            'provider_key' => 'linkedin',
            'name' => 'LinkedIn',
            'category' => 'social',
            'status' => 'active',
            'supports_oauth' => true,
            'supports_sync' => true,
            'supports_webhooks' => false,
            'dataset_discovery' => [
                'adapter' => LinkedInDatasetDiscoveryAdapter::class,
            ],
            'sync' => [
                'adapter' => LinkedInAnalyticsSyncAdapter::class,
            ],
            'config_json' => [
                'datasets' => ['organizations', 'organization_pages', 'organic_statistics'],
                'required_scopes' => [
                    'openid',
                    'profile',
                    'r_organization_social',
                    'rw_organization_admin',
                ],
                'oauth' => [
                    'authorization_url' => env('LINKEDIN_ANALYTICS_AUTHORIZATION_URL', 'https://www.linkedin.com/oauth/v2/authorization'),
                    'token_url' => env('LINKEDIN_ANALYTICS_TOKEN_URL', 'https://www.linkedin.com/oauth/v2/accessToken'),
                    'client_id' => env('LINKEDIN_ANALYTICS_CLIENT_ID', env('LINKEDIN_CLIENT_ID', 'linkedin-analytics-client-id')),
                    'client_secret' => env('LINKEDIN_ANALYTICS_CLIENT_SECRET', env('LINKEDIN_CLIENT_SECRET')),
                    'redirect_uri' => env('LINKEDIN_ANALYTICS_REDIRECT_URI', env('APP_URL', 'http://localhost').'/connectors/oauth/linkedin/callback'),
                    'scopes' => [
                        'openid',
                        'profile',
                        'r_organization_social',
                        'rw_organization_admin',
                    ],
                ],
                'api' => [
                    'base_url' => env('LINKEDIN_ANALYTICS_API_BASE_URL', 'https://api.linkedin.com/v2'),
                    'timeout_seconds' => (int) env('LINKEDIN_ANALYTICS_API_TIMEOUT_SECONDS', 15),
                    'page_size' => (int) env('LINKEDIN_ANALYTICS_API_PAGE_SIZE', 100),
                    'linkedin_version' => env('LINKEDIN_ANALYTICS_API_VERSION'),
                ],
                'discovery' => [
                    'include_organization_pages' => (bool) env('LINKEDIN_ANALYTICS_DISCOVER_ORGANIZATION_PAGES', true),
                    'role' => env('LINKEDIN_ANALYTICS_DISCOVERY_ROLE', 'ADMINISTRATOR'),
                    'state' => env('LINKEDIN_ANALYTICS_DISCOVERY_STATE', 'APPROVED'),
                ],
                'sync' => [
                    'resources' => ['share_statistics', 'follower_statistics'],
                    'metrics' => [
                        'impressions',
                        'clicks',
                        'reactions',
                        'comments',
                        'shares',
                        'followers',
                        'engagementRate',
                    ],
                    'dimensions' => [
                        'date',
                        'organization',
                        'post',
                        'mediaType',
                        'campaign',
                        'content',
                    ],
                ],
            ],
        ],
    ],
];
