<?php

use App\Services\DataConnectors\Ads\GoogleAdsDatasetDiscoveryAdapter;
use App\Services\DataConnectors\Ads\GoogleAdsSyncAdapter;
use App\Services\DataConnectors\Ads\MetaAdsDatasetDiscoveryAdapter;
use App\Services\DataConnectors\Ads\MetaAdsSyncAdapter;
use App\Services\DataConnectors\Ads\MicrosoftAdsDatasetDiscoveryAdapter;
use App\Services\DataConnectors\Ads\MicrosoftAdsSyncAdapter;
use App\Services\DataConnectors\Crm\HubSpotDatasetDiscoveryAdapter;
use App\Services\DataConnectors\Crm\HubSpotSyncAdapter;
use App\Services\DataConnectors\Crm\PipedriveDatasetDiscoveryAdapter;
use App\Services\DataConnectors\Crm\PipedriveSyncAdapter;
use App\Services\DataConnectors\Crm\SalesforceDatasetDiscoveryAdapter;
use App\Services\DataConnectors\Crm\SalesforceSyncAdapter;
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

    'backfills' => [
        'default_chunk_days' => (int) env('DATA_CONNECTOR_BACKFILL_DEFAULT_CHUNK_DAYS', 7),
        'max_chunk_days' => (int) env('DATA_CONNECTOR_BACKFILL_MAX_CHUNK_DAYS', 30),
        'max_requested_days' => (int) env('DATA_CONNECTOR_BACKFILL_MAX_REQUESTED_DAYS', 90),
        'max_ranges_per_request' => (int) env('DATA_CONNECTOR_BACKFILL_MAX_RANGES_PER_REQUEST', 30),
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
                    'client_id' => env('GOOGLE_SEARCH_CONSOLE_CLIENT_ID'),
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
                    'client_id' => env('GOOGLE_ANALYTICS_4_CLIENT_ID'),
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
                    'client_id' => env('LINKEDIN_ANALYTICS_CLIENT_ID', env('LINKEDIN_CLIENT_ID')),
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
        'google_ads' => [
            'provider_key' => 'google_ads',
            'name' => 'Google Ads',
            'category' => 'ads',
            'status' => 'active',
            'supports_oauth' => true,
            'supports_sync' => true,
            'supports_webhooks' => false,
            'dataset_discovery' => [
                'adapter' => GoogleAdsDatasetDiscoveryAdapter::class,
            ],
            'sync' => [
                'adapter' => GoogleAdsSyncAdapter::class,
            ],
            'config_json' => [
                'datasets' => ['ad_accounts', 'campaigns', 'ad_groups', 'ads', 'creatives', 'daily_performance'],
                'required_scopes' => [
                    'https://www.googleapis.com/auth/adwords',
                ],
                'capabilities' => [
                    'auth_type' => 'oauth2',
                    'supported_datasets' => ['ad_accounts', 'campaigns', 'ad_groups', 'ads', 'creatives', 'daily_performance'],
                    'sync_modes' => ['scheduled', 'manual', 'backfill', 'async_report'],
                    'rate_limit_model' => 'developer_token_and_customer_quota',
                    'supports_async_reports' => true,
                    'supports_webhooks' => false,
                    'supports_incremental_sync' => true,
                    'required_scopes' => ['https://www.googleapis.com/auth/adwords'],
                ],
                'quota' => [
                    'hourly' => ['limit' => (int) env('GOOGLE_ADS_QUOTA_HOURLY', 5000), 'warning_threshold_percent' => 80],
                    'daily' => ['limit' => (int) env('GOOGLE_ADS_QUOTA_DAILY', 50000), 'warning_threshold_percent' => 80],
                ],
                'oauth' => [
                    'authorization_url' => env('GOOGLE_ADS_AUTHORIZATION_URL', 'https://accounts.google.com/o/oauth2/v2/auth'),
                    'token_url' => env('GOOGLE_ADS_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
                    'revoke_url' => env('GOOGLE_ADS_REVOKE_URL', 'https://oauth2.googleapis.com/revoke'),
                    'client_id' => env('GOOGLE_ADS_CLIENT_ID'),
                    'client_secret' => env('GOOGLE_ADS_CLIENT_SECRET'),
                    'redirect_uri' => env('GOOGLE_ADS_REDIRECT_URI', env('APP_URL', 'http://localhost').'/connectors/oauth/google-ads/callback'),
                    'scopes' => ['https://www.googleapis.com/auth/adwords'],
                    'authorization_params' => [
                        'access_type' => 'offline',
                        'prompt' => 'consent',
                        'include_granted_scopes' => 'true',
                    ],
                ],
                'api' => [
                    'base_url' => env('GOOGLE_ADS_API_BASE_URL', 'https://googleads.googleapis.com/v18'),
                    'timeout_seconds' => (int) env('GOOGLE_ADS_API_TIMEOUT_SECONDS', 30),
                ],
            ],
        ],
        'microsoft_ads' => [
            'provider_key' => 'microsoft_ads',
            'name' => 'Microsoft Ads',
            'category' => 'ads',
            'status' => 'active',
            'supports_oauth' => true,
            'supports_sync' => true,
            'supports_webhooks' => false,
            'dataset_discovery' => [
                'adapter' => MicrosoftAdsDatasetDiscoveryAdapter::class,
            ],
            'sync' => [
                'adapter' => MicrosoftAdsSyncAdapter::class,
            ],
            'config_json' => [
                'datasets' => ['ad_accounts', 'campaigns', 'ad_groups', 'ads', 'creatives', 'daily_performance'],
                'required_scopes' => [
                    'https://ads.microsoft.com/msads.manage',
                    'offline_access',
                ],
                'capabilities' => [
                    'auth_type' => 'oauth2',
                    'supported_datasets' => ['ad_accounts', 'campaigns', 'ad_groups', 'ads', 'creatives', 'daily_performance'],
                    'sync_modes' => ['scheduled', 'manual', 'backfill', 'async_report'],
                    'rate_limit_model' => 'customer_account_hourly_and_daily',
                    'supports_async_reports' => true,
                    'supports_webhooks' => false,
                    'supports_incremental_sync' => true,
                    'required_scopes' => ['https://ads.microsoft.com/msads.manage', 'offline_access'],
                ],
                'quota' => [
                    'hourly' => ['limit' => (int) env('MICROSOFT_ADS_QUOTA_HOURLY', 4000), 'warning_threshold_percent' => 80],
                    'daily' => ['limit' => (int) env('MICROSOFT_ADS_QUOTA_DAILY', 40000), 'warning_threshold_percent' => 80],
                ],
                'oauth' => [
                    'authorization_url' => env('MICROSOFT_ADS_AUTHORIZATION_URL', 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize'),
                    'token_url' => env('MICROSOFT_ADS_TOKEN_URL', 'https://login.microsoftonline.com/common/oauth2/v2.0/token'),
                    'client_id' => env('MICROSOFT_ADS_CLIENT_ID'),
                    'client_secret' => env('MICROSOFT_ADS_CLIENT_SECRET'),
                    'redirect_uri' => env('MICROSOFT_ADS_REDIRECT_URI', env('APP_URL', 'http://localhost').'/connectors/oauth/microsoft-ads/callback'),
                    'scopes' => ['https://ads.microsoft.com/msads.manage', 'offline_access'],
                ],
                'api' => [
                    'base_url' => env('MICROSOFT_ADS_API_BASE_URL', 'https://api.ads.microsoft.com/v13'),
                    'timeout_seconds' => (int) env('MICROSOFT_ADS_API_TIMEOUT_SECONDS', 30),
                ],
            ],
        ],
        'meta_ads' => [
            'provider_key' => 'meta_ads',
            'name' => 'Meta Ads',
            'category' => 'ads',
            'status' => 'active',
            'supports_oauth' => true,
            'supports_sync' => true,
            'supports_webhooks' => true,
            'dataset_discovery' => [
                'adapter' => MetaAdsDatasetDiscoveryAdapter::class,
            ],
            'sync' => [
                'adapter' => MetaAdsSyncAdapter::class,
            ],
            'config_json' => [
                'datasets' => ['ad_accounts', 'campaigns', 'ad_sets', 'ads', 'creatives', 'daily_performance'],
                'required_scopes' => [
                    'ads_read',
                    'business_management',
                ],
                'capabilities' => [
                    'auth_type' => 'oauth2',
                    'supported_datasets' => ['ad_accounts', 'campaigns', 'ad_sets', 'ads', 'creatives', 'daily_performance'],
                    'sync_modes' => ['scheduled', 'manual', 'backfill', 'async_report'],
                    'rate_limit_model' => 'app_and_ad_account_business_use_case',
                    'supports_async_reports' => true,
                    'supports_webhooks' => true,
                    'supports_incremental_sync' => true,
                    'required_scopes' => ['ads_read', 'business_management'],
                ],
                'quota' => [
                    'hourly' => ['limit' => (int) env('META_ADS_QUOTA_HOURLY', 5000), 'warning_threshold_percent' => 80],
                    'daily' => ['limit' => (int) env('META_ADS_QUOTA_DAILY', 50000), 'warning_threshold_percent' => 80],
                ],
                'oauth' => [
                    'authorization_url' => env('META_ADS_AUTHORIZATION_URL', 'https://www.facebook.com/v20.0/dialog/oauth'),
                    'token_url' => env('META_ADS_TOKEN_URL', 'https://graph.facebook.com/v20.0/oauth/access_token'),
                    'client_id' => env('META_ADS_CLIENT_ID'),
                    'client_secret' => env('META_ADS_CLIENT_SECRET'),
                    'redirect_uri' => env('META_ADS_REDIRECT_URI', env('APP_URL', 'http://localhost').'/connectors/oauth/meta-ads/callback'),
                    'scopes' => ['ads_read', 'business_management'],
                ],
                'api' => [
                    'base_url' => env('META_ADS_API_BASE_URL', 'https://graph.facebook.com/v20.0'),
                    'timeout_seconds' => (int) env('META_ADS_API_TIMEOUT_SECONDS', 30),
                    'page_size' => (int) env('META_ADS_API_PAGE_SIZE', 100),
                ],
                'webhooks' => [
                    'events' => ['ad_account', 'campaign', 'ad'],
                ],
            ],
        ],
        'hubspot' => [
            'provider_key' => 'hubspot',
            'name' => 'HubSpot',
            'category' => 'crm',
            'status' => 'active',
            'supports_oauth' => true,
            'supports_sync' => true,
            'supports_webhooks' => true,
            'dataset_discovery' => [
                'adapter' => HubSpotDatasetDiscoveryAdapter::class,
            ],
            'sync' => [
                'adapter' => HubSpotSyncAdapter::class,
            ],
            'config_json' => [
                'datasets' => ['contacts', 'companies', 'deals', 'activities', 'owners', 'pipelines', 'stages', 'custom_properties'],
                'required_scopes' => [
                    'crm.objects.contacts.read',
                    'crm.objects.companies.read',
                    'crm.objects.deals.read',
                    'crm.schemas.contacts.read',
                    'crm.schemas.companies.read',
                    'crm.schemas.deals.read',
                    'oauth',
                ],
                'capabilities' => [
                    'auth_type' => 'oauth2',
                    'supported_datasets' => ['contacts', 'companies', 'deals', 'activities', 'owners', 'pipelines', 'stages', 'custom_properties'],
                    'sync_modes' => ['scheduled', 'manual', 'backfill', 'cursor_incremental'],
                    'rate_limit_model' => 'app_and_portal_hourly_daily',
                    'supports_async_reports' => false,
                    'supports_webhooks' => true,
                    'supports_incremental_sync' => true,
                    'required_scopes' => ['crm.objects.contacts.read', 'crm.objects.companies.read', 'crm.objects.deals.read', 'oauth'],
                ],
                'quota' => [
                    'hourly' => ['limit' => (int) env('HUBSPOT_QUOTA_HOURLY', 9000), 'warning_threshold_percent' => 80],
                    'daily' => ['limit' => (int) env('HUBSPOT_QUOTA_DAILY', 100000), 'warning_threshold_percent' => 80],
                ],
                'oauth' => [
                    'authorization_url' => env('HUBSPOT_AUTHORIZATION_URL', 'https://app.hubspot.com/oauth/authorize'),
                    'token_url' => env('HUBSPOT_TOKEN_URL', 'https://api.hubapi.com/oauth/v1/token'),
                    'client_id' => env('HUBSPOT_CLIENT_ID'),
                    'client_secret' => env('HUBSPOT_CLIENT_SECRET'),
                    'redirect_uri' => env('HUBSPOT_REDIRECT_URI', env('APP_URL', 'http://localhost').'/connectors/oauth/hubspot/callback'),
                    'scopes' => ['crm.objects.contacts.read', 'crm.objects.companies.read', 'crm.objects.deals.read', 'crm.schemas.contacts.read', 'crm.schemas.companies.read', 'crm.schemas.deals.read', 'oauth'],
                ],
                'api' => [
                    'base_url' => env('HUBSPOT_API_BASE_URL', 'https://api.hubapi.com'),
                    'timeout_seconds' => (int) env('HUBSPOT_API_TIMEOUT_SECONDS', 20),
                ],
                'crm' => [
                    'objects' => [
                        'contacts' => ['display_name' => 'Contacts', 'provider_object' => 'contacts', 'cursor_field' => 'updatedAt'],
                        'companies' => ['display_name' => 'Companies', 'provider_object' => 'companies', 'cursor_field' => 'updatedAt'],
                        'deals' => ['display_name' => 'Deals', 'provider_object' => 'deals', 'cursor_field' => 'updatedAt'],
                        'activities' => ['display_name' => 'Activities', 'provider_object' => 'tasks', 'cursor_field' => 'updatedAt'],
                        'owners' => ['display_name' => 'Owners', 'provider_object' => 'owners', 'cursor_field' => 'updatedAt'],
                        'pipelines' => ['display_name' => 'Pipelines', 'provider_object' => 'pipelines', 'cursor_field' => 'updatedAt'],
                        'stages' => ['display_name' => 'Stages', 'provider_object' => 'stages', 'cursor_field' => 'updatedAt'],
                        'custom_properties' => ['display_name' => 'Custom Properties', 'provider_object' => 'deals', 'cursor_field' => 'updatedAt'],
                    ],
                ],
                'webhooks' => [
                    'events' => ['contact.creation', 'contact.propertyChange', 'company.creation', 'deal.creation', 'deal.propertyChange'],
                ],
            ],
        ],
        'salesforce' => [
            'provider_key' => 'salesforce',
            'name' => 'Salesforce',
            'category' => 'crm',
            'status' => 'active',
            'supports_oauth' => true,
            'supports_sync' => true,
            'supports_webhooks' => true,
            'dataset_discovery' => [
                'adapter' => SalesforceDatasetDiscoveryAdapter::class,
            ],
            'sync' => [
                'adapter' => SalesforceSyncAdapter::class,
            ],
            'config_json' => [
                'datasets' => ['contacts', 'accounts', 'opportunities', 'activities', 'users', 'pipelines', 'stages', 'custom_fields'],
                'required_scopes' => ['api', 'refresh_token', 'offline_access'],
                'capabilities' => [
                    'auth_type' => 'oauth2',
                    'supported_datasets' => ['contacts', 'accounts', 'opportunities', 'activities', 'users', 'pipelines', 'stages', 'custom_fields'],
                    'sync_modes' => ['scheduled', 'manual', 'backfill', 'cursor_incremental'],
                    'rate_limit_model' => 'org_daily_api_requests',
                    'supports_async_reports' => false,
                    'supports_webhooks' => true,
                    'supports_incremental_sync' => true,
                    'required_scopes' => ['api', 'refresh_token', 'offline_access'],
                ],
                'quota' => [
                    'hourly' => ['limit' => (int) env('SALESFORCE_QUOTA_HOURLY', 10000), 'warning_threshold_percent' => 80],
                    'daily' => ['limit' => (int) env('SALESFORCE_QUOTA_DAILY', 100000), 'warning_threshold_percent' => 80],
                ],
                'oauth' => [
                    'authorization_url' => env('SALESFORCE_AUTHORIZATION_URL', 'https://login.salesforce.com/services/oauth2/authorize'),
                    'token_url' => env('SALESFORCE_TOKEN_URL', 'https://login.salesforce.com/services/oauth2/token'),
                    'revoke_url' => env('SALESFORCE_REVOKE_URL', 'https://login.salesforce.com/services/oauth2/revoke'),
                    'client_id' => env('SALESFORCE_CLIENT_ID'),
                    'client_secret' => env('SALESFORCE_CLIENT_SECRET'),
                    'redirect_uri' => env('SALESFORCE_REDIRECT_URI', env('APP_URL', 'http://localhost').'/connectors/oauth/salesforce/callback'),
                    'scopes' => ['api', 'refresh_token', 'offline_access'],
                ],
                'api' => [
                    'base_url' => env('SALESFORCE_API_BASE_URL', 'https://login.salesforce.com/services/data/v59.0'),
                    'timeout_seconds' => (int) env('SALESFORCE_API_TIMEOUT_SECONDS', 20),
                ],
                'crm' => [
                    'objects' => [
                        'contacts' => ['display_name' => 'Contacts', 'provider_object' => 'Contact', 'cursor_field' => 'SystemModstamp'],
                        'accounts' => ['display_name' => 'Accounts', 'provider_object' => 'Account', 'cursor_field' => 'SystemModstamp'],
                        'opportunities' => ['display_name' => 'Opportunities', 'provider_object' => 'Opportunity', 'cursor_field' => 'SystemModstamp'],
                        'activities' => ['display_name' => 'Activities', 'provider_object' => 'Task', 'cursor_field' => 'SystemModstamp'],
                        'users' => ['display_name' => 'Users', 'provider_object' => 'User', 'cursor_field' => 'SystemModstamp'],
                        'pipelines' => ['display_name' => 'Pipelines', 'provider_object' => 'Opportunity', 'cursor_field' => 'SystemModstamp'],
                        'stages' => ['display_name' => 'Stages', 'provider_object' => 'Opportunity', 'cursor_field' => 'SystemModstamp'],
                        'custom_fields' => ['display_name' => 'Custom Fields', 'provider_object' => 'Opportunity', 'cursor_field' => 'SystemModstamp'],
                    ],
                ],
                'webhooks' => [
                    'events' => ['ContactChangeEvent', 'AccountChangeEvent', 'OpportunityChangeEvent'],
                ],
            ],
        ],
        'pipedrive' => [
            'provider_key' => 'pipedrive',
            'name' => 'Pipedrive',
            'category' => 'crm',
            'status' => 'active',
            'supports_oauth' => true,
            'supports_sync' => true,
            'supports_webhooks' => true,
            'dataset_discovery' => [
                'adapter' => PipedriveDatasetDiscoveryAdapter::class,
            ],
            'sync' => [
                'adapter' => PipedriveSyncAdapter::class,
            ],
            'config_json' => [
                'datasets' => ['contacts', 'companies', 'deals', 'activities', 'owners', 'pipelines', 'stages', 'custom_fields'],
                'required_scopes' => ['contacts:read', 'deals:read', 'activities:read', 'users:read'],
                'capabilities' => [
                    'auth_type' => 'oauth2',
                    'supported_datasets' => ['contacts', 'companies', 'deals', 'activities', 'owners', 'pipelines', 'stages', 'custom_fields'],
                    'sync_modes' => ['scheduled', 'manual', 'backfill', 'cursor_incremental'],
                    'rate_limit_model' => 'api_token_bucket',
                    'supports_async_reports' => false,
                    'supports_webhooks' => true,
                    'supports_incremental_sync' => true,
                    'required_scopes' => ['contacts:read', 'deals:read', 'activities:read', 'users:read'],
                ],
                'quota' => [
                    'hourly' => ['limit' => (int) env('PIPEDRIVE_QUOTA_HOURLY', 6000), 'warning_threshold_percent' => 80],
                    'daily' => ['limit' => (int) env('PIPEDRIVE_QUOTA_DAILY', 80000), 'warning_threshold_percent' => 80],
                ],
                'oauth' => [
                    'authorization_url' => env('PIPEDRIVE_AUTHORIZATION_URL', 'https://oauth.pipedrive.com/oauth/authorize'),
                    'token_url' => env('PIPEDRIVE_TOKEN_URL', 'https://oauth.pipedrive.com/oauth/token'),
                    'client_id' => env('PIPEDRIVE_CLIENT_ID'),
                    'client_secret' => env('PIPEDRIVE_CLIENT_SECRET'),
                    'redirect_uri' => env('PIPEDRIVE_REDIRECT_URI', env('APP_URL', 'http://localhost').'/connectors/oauth/pipedrive/callback'),
                    'scopes' => ['contacts:read', 'deals:read', 'activities:read', 'users:read'],
                ],
                'api' => [
                    'base_url' => env('PIPEDRIVE_API_BASE_URL', 'https://api.pipedrive.com/v1'),
                    'timeout_seconds' => (int) env('PIPEDRIVE_API_TIMEOUT_SECONDS', 20),
                ],
                'crm' => [
                    'objects' => [
                        'contacts' => ['display_name' => 'Contacts', 'provider_object' => 'persons', 'fields_endpoint' => 'personFields', 'cursor_field' => 'update_time'],
                        'companies' => ['display_name' => 'Companies', 'provider_object' => 'organizations', 'fields_endpoint' => 'organizationFields', 'cursor_field' => 'update_time'],
                        'deals' => ['display_name' => 'Deals', 'provider_object' => 'deals', 'fields_endpoint' => 'dealFields', 'cursor_field' => 'update_time'],
                        'activities' => ['display_name' => 'Activities', 'provider_object' => 'activities', 'fields_endpoint' => 'activityFields', 'cursor_field' => 'update_time'],
                        'owners' => ['display_name' => 'Owners', 'provider_object' => 'users', 'fields_endpoint' => 'userFields', 'cursor_field' => 'update_time'],
                        'pipelines' => ['display_name' => 'Pipelines', 'provider_object' => 'pipelines', 'fields_endpoint' => 'pipelineFields', 'cursor_field' => 'update_time'],
                        'stages' => ['display_name' => 'Stages', 'provider_object' => 'stages', 'fields_endpoint' => 'stageFields', 'cursor_field' => 'update_time'],
                        'custom_fields' => ['display_name' => 'Custom Fields', 'provider_object' => 'dealFields', 'fields_endpoint' => 'dealFields', 'cursor_field' => 'update_time'],
                    ],
                ],
                'webhooks' => [
                    'events' => ['added.deal', 'updated.deal', 'added.person', 'updated.person', 'added.organization', 'updated.organization'],
                ],
            ],
        ],
    ],
];
