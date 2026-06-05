<?php

return [
    'network_linking' => (bool) env('PUBLISHLAYER_FEATURE_NETWORK_LINKING', false),
    'draft_link_suggestions' => (bool) env('PUBLISHLAYER_FEATURE_DRAFT_LINK_SUGGESTIONS', false),
    'link_intelligence_jobs' => (bool) env('PUBLISHLAYER_FEATURE_LINK_INTELLIGENCE_JOBS', false),
    'research_layer' => (bool) env('PUBLISHLAYER_FEATURE_RESEARCH_LAYER', false),
    'brief_intelligence' => (bool) env('PUBLISHLAYER_FEATURE_BRIEF_INTELLIGENCE', false),
    'brief_templates' => (bool) env('PUBLISHLAYER_FEATURE_BRIEF_TEMPLATES', false),
    'content_network_analysis' => (bool) env('PUBLISHLAYER_FEATURE_CONTENT_NETWORK_ANALYSIS', false),
    'agentic_marketing' => (bool) env('PUBLISHLAYER_FEATURE_AGENTIC_MARKETING', true),
];
