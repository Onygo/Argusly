<?php

$environment = env('APP_ENV', 'production');
$linkedinTestDisclaimerDefault = in_array($environment, ['local', 'staging', 'testing'], true);

return [
    'linkedin_test_disclaimer_enabled' => env('LINKEDIN_TEST_DISCLAIMER_ENABLED', $linkedinTestDisclaimerDefault),
    'linkedin_test_disclaimer_text' => env(
        'LINKEDIN_TEST_DISCLAIMER_TEXT',
        'Test vanuit PublishLayer: automatisch gegenereerd voor een Agentic Marketing Automation demo.'
    ),
];
