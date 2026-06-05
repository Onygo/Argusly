<?php

return [
    // Layout & Navigation
    'nav' => [
        'dashboard' => 'Dashboard',
        'content' => 'Content',
        'sites' => 'Sites',
        'insights' => 'Insights',
        'brand' => 'Brand',
        'settings' => 'Settings',
        'billing' => 'Billing',
        'developer' => 'Developer',
        'notifications' => 'Notifications',
        'search' => 'Search',
        'search_placeholder' => 'Search...',
        'logout' => 'Log out',
        'profile' => 'Profile',
        'publishing' => 'Publishing',
        'administration' => 'Administration',
        'research' => 'Research',
        'workspace_intelligence' => 'Workspace Intelligence',
    ],

    // Dashboard
    'dashboard' => [
        'title' => 'Dashboard',
        'subtitle' => 'Overview of your content production and insights.',
        'content_created' => 'Content Created',
        'total_briefs' => 'Total briefs',
        'integrations' => 'Integrations',
        'connected' => 'connected',
        'available_credits' => 'Available Credits',
        'buy_more_credits' => 'Buy more credits',
        'recent_content' => 'Recent Content',
        'no_content_yet' => 'No content yet',
        'create_first_content' => 'Create your first content piece to get started.',
        'create_content' => 'Create Content',
        'view_all' => 'View all',
        'quick_actions' => 'Quick Actions',
    ],

    // Content
    'content' => [
        'title' => 'Content',
        'subtitle' => 'Manage your content pieces, briefs, and drafts.',
        'create' => 'Create Content',
        'create_new' => 'Create new content',
        'search_placeholder' => 'Search content...',
        'filter_all' => 'All',
        'filter_needs_brief' => 'Needs Brief',
        'filter_needs_draft' => 'Needs Draft',
        'filter_ready' => 'Ready',
        'filter_published' => 'Published',
        'empty' => 'No content found.',
        'empty_filtered' => 'No content matches your filters.',
        'status' => 'Status',
        'site' => 'Site',
        'created' => 'Created',
        'updated' => 'Updated',
        'actions' => 'Actions',
        'view' => 'View',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'archive' => 'Archive',
        'publish' => 'Publish',
        'schedule' => 'Schedule',
        'regenerate' => 'Regenerate',
    ],

    // Sites
    'sites' => [
        'title' => 'Sites',
        'subtitle' => 'Manage your connected WordPress sites and integrations.',
        'add_site' => 'Add Site',
        'no_sites' => 'No sites connected yet.',
        'connect_first' => 'Connect your first WordPress site to start publishing content.',
        'status_connected' => 'Connected',
        'status_disconnected' => 'Disconnected',
        'status_pending' => 'Pending',
        'test_connection' => 'Test Connection',
        'regenerate_key' => 'Regenerate Key',
        'deactivate' => 'Deactivate',
        'activate' => 'Activate',
    ],

    // Brand
    'brand' => [
        'title' => 'Brand',
        'company_profile' => 'Company Profile',
        'company_profile_subtitle' => 'Define your company\'s identity, value propositions, and content guidelines.',
        'brand_voices' => 'Brand Voices',
        'brand_voices_subtitle' => 'Manage writing styles, tones, and terminology for content generation.',
        'buyer_personas' => 'Buyer Personas',
        'team_personas' => 'Team Personas',
        'team_personas_subtitle' => 'Define author personas with specific writing perspectives and expertise for content generation.',
    ],

    // Settings
    'settings' => [
        'title' => 'Settings',
        'subtitle' => 'Manage your account and workspace settings.',
        'organization' => 'Organization',
        'workspace' => 'Workspace',
        'team' => 'Team',
        'notifications' => 'Notifications',
        'save' => 'Save',
        'saved' => 'Settings saved.',
    ],

    // Billing
    'billing' => [
        'title' => 'Billing',
        'subtitle' => 'Manage your subscription and purchase credit packs.',
        'current_plan' => 'Current Plan',
        'credits_remaining' => 'Credits Remaining',
        'buy_credits' => 'Buy Credits',
        'invoices' => 'Invoices',
        'no_invoices' => 'No invoices yet.',
    ],
    'credits' => [
        'low_warning' => [
            'title' => 'Credits are running low',
            'body' => 'Your available credits are running low. Buy extra credits or upgrade your plan to avoid interruptions.',
            'body_with_automation' => 'Your available credits are running low. :count active automations are enabled. Without additional credits, scheduled runs may fail or stop.',
            'balance_line' => 'Available credits: :available',
            'cta' => 'View credits',
            'email_subject' => 'Credits are running low',
            'email_greeting' => 'Heads up,',
            'email_balance' => 'Current available credits: :available.',
            'email_automation_hint' => ':count active automations are configured. Next scheduled run: :next_run.',
            'email_footer' => 'Top up credits or update your plan to avoid interruptions in generation or publishing.',
            'automation_blocked' => 'Automation did not start because available credits (:available) are below the required minimum (:required).',
        ],
    ],

    // Common
    'common' => [
        'save' => 'Save',
        'cancel' => 'Cancel',
        'delete' => 'Delete',
        'edit' => 'Edit',
        'create' => 'Create',
        'update' => 'Update',
        'close' => 'Close',
        'back' => 'Back',
        'next' => 'Next',
        'previous' => 'Previous',
        'loading' => 'Loading...',
        'error' => 'Error',
        'success' => 'Success',
        'warning' => 'Warning',
        'info' => 'Info',
        'confirm' => 'Confirm',
        'yes' => 'Yes',
        'no' => 'No',
        'or' => 'or',
        'and' => 'and',
        'na' => 'n/a',
        'workspace' => 'Workspace',
        'organization' => 'Organization',
    ],

    // Status badges
    'status' => [
        'active' => 'Active',
        'inactive' => 'Inactive',
        'pending' => 'Pending',
        'draft' => 'Draft',
        'published' => 'Published',
        'scheduled' => 'Scheduled',
        'archived' => 'Archived',
        'connected' => 'Connected',
        'disconnected' => 'Disconnected',
    ],

    // Time
    'time' => [
        'just_now' => 'Just now',
        'minutes_ago' => ':count minutes ago',
        'hours_ago' => ':count hours ago',
        'days_ago' => ':count days ago',
        'today' => 'Today',
        'yesterday' => 'Yesterday',
    ],

    // Validation messages
    'validation' => [
        'required' => 'This field is required.',
        'email' => 'Please enter a valid email address.',
        'min' => 'Minimum :min characters required.',
        'max' => 'Maximum :max characters allowed.',
    ],
];
