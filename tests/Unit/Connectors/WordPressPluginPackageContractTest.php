<?php

it('reports the wordpress connector release version', function () {
    $plugin = file_get_contents(base_path('packages/wordpress-plugin/argusly-connector.php'));

    expect($plugin)->toContain('Version: 0.1.5')
        ->and($plugin)->toContain("ARGUSLY_CONNECTOR_VERSION', '0.1.5'");
});

it('accepts canonical argusly auth headers and legacy webhook aliases', function () {
    $plugin = file_get_contents(base_path('packages/wordpress-plugin/argusly-connector.php'));

    expect($plugin)->toContain("'/webhook/draft'")
        ->and($plugin)->toContain("'/webhook/draft/(?P<id>[\\d]+)'")
        ->and($plugin)->toContain("get_header('x-argusly-api-key')")
        ->and($plugin)->toContain("get_header('x-argusly-site-token')")
        ->and($plugin)->toContain('article_payload_normalization');
});

it('stores argusly metadata and seo provider fields in wordpress post meta', function () {
    $plugin = file_get_contents(base_path('packages/wordpress-plugin/argusly-connector.php'));

    expect($plugin)->toContain('argusly_ai_metadata')
        ->and($plugin)->toContain('argusly_answer_blocks')
        ->and($plugin)->toContain('argusly_seo_sync')
        ->and($plugin)->toContain('_yoast_wpseo_title')
        ->and($plugin)->toContain('rank_math_title')
        ->and($plugin)->toContain('_aioseo_title')
        ->and($plugin)->toContain('_pl_seo_title');
});
