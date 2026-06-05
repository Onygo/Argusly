<?php

return [
    'toggles' => [
        'block_suspicious_traffic' => env('SECURITY_BLOCK_SUSPICIOUS_TRAFFIC', true),
        'log_suspicious_traffic' => env('SECURITY_LOG_SUSPICIOUS_TRAFFIC', true),
        'log_only_mode' => env('SECURITY_LOG_ONLY_MODE', env('SECURITY_SUSPICIOUS_TRAFFIC_LOG_ONLY', false)),
        'protect_heavy_endpoints' => env('SECURITY_PROTECT_HEAVY_ENDPOINTS', true),
    ],

    'suspicious_paths' => [
        '/.env',
        '/.env.example',
        '/.git',
        '/.git/config',
        '/vendor',
        '/wp-admin',
        '/wp-login.php',
        '/xmlrpc.php',
        '/phpmyadmin',
        '/phpMyAdmin',
        '/server-status',
    ],

    'suspicious_user_agents' => [
        'sqlmap',
        'nikto',
        'masscan',
        'nmap',
        'zgrab',
        'gobuster',
        'dirbuster',
        'ffuf',
        'feroxbuster',
        'nuclei',
        'metasploit',
    ],

    'suspicious_patterns' => [
        '/\bunion(?:\/\*.*?\*\/|\s|%20)+select\b/i',
        '/\bselect(?:\/\*.*?\*\/|\s|%20)+[\w\*,\s]{0,80}(?:\/\*.*?\*\/|\s|%20)+from\b/i',
        '/\.\.(?:\/|\\\\)/i',
        '/<script\b/i',
        '/\bbase64(?:_|%5f)?decode\b/i',
        '/\b(?:cmd|exec|shell|system)\s*=/i',
        '/\b(?:php|expect|data|file):\/\//i',
    ],

    'max_query_length' => (int) env('SECURITY_MAX_QUERY_LENGTH', 2000),

    'rate_limits' => [
        'web_per_minute' => (int) env('THROTTLE_WEB_PER_MINUTE', 120),
        'api_per_minute' => (int) env('THROTTLE_API_PER_MINUTE', 60),
        'login_per_minute' => (int) env('THROTTLE_LOGIN_PER_MINUTE', 5),
        'password_reset_per_minute' => (int) env('THROTTLE_PASSWORD_RESET_PER_MINUTE', 5),
        'contact_per_minute' => (int) env('THROTTLE_CONTACT_PER_MINUTE', 5),
        'organization_register_per_hour' => (int) env('THROTTLE_ORGANIZATION_REGISTER_PER_HOUR', 3),
        'organization_register_per_day' => (int) env('THROTTLE_ORGANIZATION_REGISTER_PER_DAY', 10),
        'organization_register_domain_per_hour' => (int) env('THROTTLE_ORGANIZATION_REGISTER_DOMAIN_PER_HOUR', 10),
        'organization_register_domain_per_day' => (int) env('THROTTLE_ORGANIZATION_REGISTER_DOMAIN_PER_DAY', 25),
        'heavy_per_minute' => (int) env('THROTTLE_HEAVY_PER_MINUTE', 10),
        'analytics_events_per_minute' => (int) env('THROTTLE_ANALYTICS_EVENTS_PER_MINUTE', 120),
        'integration_api_per_minute' => (int) env('THROTTLE_INTEGRATION_API_PER_MINUTE', 120),
        'webhook_per_minute' => (int) env('THROTTLE_WEBHOOK_PER_MINUTE', 60),
    ],

    'registration' => [
        'honeypot_fields' => [
            'company_website',
        ],
        'disposable_email_domains' => [
            '10minutemail.com',
            'guerrillamail.com',
            'guerrillamail.net',
            'mailinator.com',
            'sharklasers.com',
            'temp-mail.org',
            'tempmail.com',
            'throwawaymail.com',
            'trashmail.com',
            'yopmail.com',
        ],
    ],

    'responses' => [
        'throttle_message_web' => env('SECURITY_THROTTLE_MESSAGE_WEB', 'Too many requests. Please try again shortly.'),
        'throttle_message_api' => env('SECURITY_THROTTLE_MESSAGE_API', 'Too many requests. Please try again shortly.'),
        'forbidden_message_web' => env('SECURITY_FORBIDDEN_MESSAGE_WEB', 'Forbidden.'),
        'forbidden_message_api' => env('SECURITY_FORBIDDEN_MESSAGE_API', 'Forbidden.'),
    ],

    'logging' => [
        'enabled' => env('SECURITY_LOG_SUSPICIOUS_TRAFFIC', true),
        'channel' => env('SECURITY_LOG_CHANNEL', 'security'),
    ],

    // Legacy keys remain available so existing config lookups keep working while
    // the application moves to the flatter security config contract.
    'suspicious_traffic' => [
        'enabled' => env('SECURITY_BLOCK_SUSPICIOUS_TRAFFIC', true),
        'log_only' => env('SECURITY_LOG_ONLY_MODE', env('SECURITY_SUSPICIOUS_TRAFFIC_LOG_ONLY', false)),
        'max_query_length' => (int) env('SECURITY_MAX_QUERY_LENGTH', 2000),
        'paths' => [
            '/.env',
            '/.env.example',
            '/.git',
            '/.git/config',
            '/vendor',
            '/wp-admin',
            '/wp-login.php',
            '/xmlrpc.php',
            '/phpmyadmin',
            '/phpMyAdmin',
            '/server-status',
        ],
        'user_agents' => [
            'sqlmap',
            'nikto',
            'masscan',
            'nmap',
            'zgrab',
            'gobuster',
            'dirbuster',
            'ffuf',
            'feroxbuster',
            'nuclei',
            'metasploit',
        ],
        'patterns' => [
            '/\bunion(?:\/\*.*?\*\/|\s|%20)+select\b/i',
            '/\bselect(?:\/\*.*?\*\/|\s|%20)+[\w\*,\s]{0,80}(?:\/\*.*?\*\/|\s|%20)+from\b/i',
            '/\.\.(?:\/|\\\\)/i',
            '/<script\b/i',
            '/\bbase64(?:_|%5f)?decode\b/i',
            '/\b(?:cmd|exec|shell|system)\s*=/i',
            '/\b(?:php|expect|data|file):\/\//i',
        ],
    ],

    'throttle' => [
        'web' => [
            'per_minute' => (int) env('THROTTLE_WEB_PER_MINUTE', 120),
        ],
        'api' => [
            'per_minute' => (int) env('THROTTLE_API_PER_MINUTE', 60),
        ],
        'login' => [
            'per_minute' => (int) env('THROTTLE_LOGIN_PER_MINUTE', 5),
        ],
        'password_reset' => [
            'per_minute' => (int) env('THROTTLE_PASSWORD_RESET_PER_MINUTE', 5),
        ],
        'contact_form' => [
            'per_minute' => (int) env('THROTTLE_CONTACT_PER_MINUTE', 5),
        ],
        'heavy_actions' => [
            'per_minute' => (int) env('THROTTLE_HEAVY_PER_MINUTE', 10),
        ],
        'analytics_events' => [
            'per_minute' => (int) env('THROTTLE_ANALYTICS_EVENTS_PER_MINUTE', 120),
        ],
        'integration_api' => [
            'default_per_minute' => (int) env('THROTTLE_INTEGRATION_API_PER_MINUTE', 120),
        ],
        'webhooks' => [
            'per_minute' => (int) env('THROTTLE_WEBHOOK_PER_MINUTE', 60),
        ],
    ],

    'heavy_routes' => [
        'enabled' => env('SECURITY_PROTECT_HEAVY_ENDPOINTS', true),
        'heavy' => [
            'limiter' => 'heavy',
        ],
        'search' => [
            'limiter' => 'heavy',
            'max_query_length' => (int) env('SECURITY_HEAVY_SEARCH_MAX_QUERY_LENGTH', 120),
        ],
        'export' => [
            'limiter' => 'heavy',
        ],
        'import' => [
            'limiter' => 'heavy',
        ],
        'ai' => [
            'limiter' => 'heavy',
        ],
        'audit' => [
            'limiter' => 'heavy',
        ],
        'report' => [
            'limiter' => 'heavy',
        ],
        'webhook' => [
            'limiter' => 'webhook-public',
        ],
    ],

];
