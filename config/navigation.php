<?php

return [
    'app' => [
        [
            'label' => 'navigation.dashboard',
            'route' => 'dashboard',
            'modules' => ['core'],
            'permission' => 'view_dashboard',
        ],
        [
            'label' => 'navigation.intelligence',
            'route' => 'app.intelligence',
            'modules' => ['core'],
            'permission' => 'view_dashboard',
        ],
        [
            'label' => 'navigation.visibility',
            'route' => 'app.visibility',
            'modules' => ['visibility'],
            'permission' => 'view_visibility',
        ],
        [
            'label' => 'navigation.competitors',
            'route' => 'app.competitors',
            'modules' => ['competitive_intelligence'],
            'permission' => 'view_competitive_intelligence',
        ],
        [
            'label' => 'navigation.topics',
            'route' => 'app.topics.index',
            'modules' => ['core'],
            'permission' => 'view_dashboard',
        ],
        [
            'label' => 'navigation.mentions',
            'route' => 'app.mentions',
            'modules' => ['visibility'],
            'permission' => 'view_visibility',
        ],
        [
            'label' => 'navigation.content',
            'route' => 'app.content.index',
            'modules' => ['content'],
            'permission' => 'view_content',
        ],
        [
            'label' => 'navigation.campaigns',
            'route' => 'app.campaigns',
            'modules' => ['campaigns'],
            'permission' => 'view_campaigns',
        ],
        [
            'label' => 'navigation.social_posts',
            'route' => 'app.social-posts.index',
            'modules' => ['content', 'agentic_social'],
            'permission' => 'view_content',
        ],
        [
            'label' => 'navigation.calendar',
            'route' => 'app.calendar',
            'modules' => ['content'],
            'permission' => 'view_content',
        ],
        [
            'label' => 'navigation.agents',
            'route' => 'app.agents',
            'modules' => ['agentic_content', 'agentic_social'],
            'permission' => 'view_agents',
        ],
        [
            'label' => 'navigation.automations',
            'route' => 'app.automations',
            'modules' => ['agentic_content', 'agentic_social'],
            'permission' => 'view_agents',
            'badge' => 'Soon',
        ],
        [
            'label' => 'navigation.reports',
            'route' => 'app.reports',
            'modules' => ['core'],
            'permission' => 'view_dashboard',
        ],
        [
            'label' => 'navigation.relationships',
            'route' => 'app.relationships',
            'modules' => ['core'],
            'permission' => 'view_dashboard',
        ],
        [
            'label' => 'navigation.sources',
            'route' => 'app.sources.index',
            'modules' => ['core'],
            'permission' => 'manage_account',
        ],
        [
            'label' => 'navigation.domain_events',
            'route' => 'app.domain-events',
            'modules' => ['core'],
            'permission' => 'manage_account',
        ],
        [
            'label' => 'navigation.settings',
            'route' => 'settings.account',
            'modules' => ['core'],
            'permission' => 'manage_account',
        ],
    ],
];
