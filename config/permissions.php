<?php

return [
    'permissions' => [
        'dashboard' => [
            'view_dashboard',
        ],
        'account' => [
            'manage_platform',
            'manage_account',
            'manage_users',
            'manage_billing',
        ],
        'content' => [
            'view_content',
            'create_content',
            'edit_content',
            'publish_content',
            'bypass_approval',
        ],
        'visibility' => [
            'view_visibility',
            'manage_visibility',
            'view_competitive_intelligence',
            'view_lead_intelligence',
        ],
        'campaigns' => [
            'view_campaigns',
            'manage_campaigns',
        ],
        'social' => [
            'view_social',
            'manage_social',
        ],
        'agents' => [
            'view_agents',
            'run_agents',
        ],
    ],

    'module_requirements' => [
        'core' => [
            'manage_platform',
            'view_dashboard',
            'manage_account',
            'manage_users',
            'manage_billing',
        ],
        'content' => [
            'view_content',
            'create_content',
            'edit_content',
            'publish_content',
            'bypass_approval',
        ],
        'visibility' => [
            'view_visibility',
            'manage_visibility',
        ],
        'competitive_intelligence' => [
            'view_competitive_intelligence',
        ],
        'lead_intelligence' => [
            'view_lead_intelligence',
        ],
        'campaigns' => [
            'view_campaigns',
            'manage_campaigns',
        ],
        'marketing_os' => [
            'view_campaigns',
            'manage_campaigns',
        ],
        'social' => [
            'view_social',
            'manage_social',
        ],
        'agentic_content' => [
            'view_agents',
            'run_agents',
        ],
        'agentic_social' => [
            'view_agents',
            'run_agents',
        ],
    ],

    'roles' => [
        'platform_admin' => [
            'display_name' => 'Platform Admin',
            'priority' => 110,
            'all_permissions' => true,
            'permissions' => [],
        ],
        'owner' => [
            'display_name' => 'Owner',
            'priority' => 100,
            'all_permissions' => true,
            'permissions' => [],
        ],
        'admin' => [
            'display_name' => 'Admin',
            'priority' => 90,
            'permissions' => [
                'view_dashboard',
                'manage_account',
                'manage_users',
                'view_content',
                'create_content',
                'edit_content',
                'publish_content',
                'bypass_approval',
                'view_visibility',
                'manage_visibility',
                'view_competitive_intelligence',
                'view_lead_intelligence',
                'view_campaigns',
                'manage_campaigns',
                'view_social',
                'manage_social',
                'view_agents',
                'run_agents',
            ],
        ],
        'manager' => [
            'display_name' => 'Manager',
            'priority' => 70,
            'permissions' => [
                'view_dashboard',
                'view_content',
                'create_content',
                'edit_content',
                'publish_content',
                'bypass_approval',
                'view_visibility',
                'manage_visibility',
                'view_competitive_intelligence',
                'view_lead_intelligence',
                'view_campaigns',
                'manage_campaigns',
                'view_social',
                'manage_social',
                'view_agents',
                'run_agents',
            ],
        ],
        'editor' => [
            'display_name' => 'Editor',
            'priority' => 50,
            'permissions' => [
                'view_dashboard',
                'view_content',
                'create_content',
                'edit_content',
                'view_visibility',
                'view_competitive_intelligence',
                'view_campaigns',
                'view_social',
                'view_agents',
            ],
        ],
        'publisher' => [
            'display_name' => 'Publisher',
            'priority' => 60,
            'permissions' => [
                'view_dashboard',
                'view_content',
                'create_content',
                'edit_content',
                'publish_content',
                'bypass_approval',
                'view_visibility',
                'view_competitive_intelligence',
                'view_campaigns',
                'view_social',
                'view_agents',
            ],
        ],
        'viewer' => [
            'display_name' => 'Viewer',
            'priority' => 20,
            'permissions' => [
                'view_dashboard',
                'view_content',
                'view_visibility',
                'view_competitive_intelligence',
                'view_campaigns',
                'view_social',
                'view_agents',
            ],
        ],
        'billing' => [
            'display_name' => 'Billing',
            'priority' => 30,
            'permissions' => [
                'view_dashboard',
                'manage_billing',
            ],
        ],
        'external' => [
            'display_name' => 'External',
            'priority' => 10,
            'permissions' => [
                'view_dashboard',
                'view_content',
                'view_visibility',
                'view_competitive_intelligence',
            ],
        ],
    ],
];
