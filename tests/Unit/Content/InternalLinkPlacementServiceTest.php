<?php

use App\Services\Content\InternalLinkPlacementService;

it('removes duplicate related reading links when the same url already exists inline', function () {
    $service = new InternalLinkPlacementService();

    $html = implode("\n", [
        '<p>Teams often start with an <a href="https://example.com/content-clusters">existing content clusters guide</a>.</p>',
        '<p>Good governance workflows keep ownership clear across planning and publishing.</p>',
        '<p><strong>Related reading:</strong> <a href="https://example.com/content-clusters">Related article 1</a> · <a href="https://example.com/governance-workflows">Related article 2</a></p>',
    ]);

    $result = $service->placeIntoHtml($html, [
        [
            'target_url' => 'https://example.com/content-clusters',
            'anchor_text' => 'content clusters',
            'title' => 'Content clusters',
        ],
        [
            'target_url' => 'https://example.com/governance-workflows',
            'anchor_text' => 'governance workflows',
            'title' => 'Governance workflows',
        ],
    ]);

    expect(substr_count($result['updated_html'], 'https://example.com/content-clusters'))->toBe(1)
        ->and(substr_count($result['updated_html'], 'https://example.com/governance-workflows'))->toBe(1)
        ->and($result['updated_html'])->not->toContain('Related article 1')
        ->and($result['updated_html'])->not->toContain('Related reading:');
});

it('removes generated placeholder related article paragraphs', function () {
    $service = new InternalLinkPlacementService();

    $html = implode("\n", [
        '<p>To go deeper into designing content clusters, explore resources like <a href="https://example.com/one">Related article 1</a> and <a href="https://example.com/two">Related article 2</a>.</p>',
        '<p>A strong content operation connects strategy, production, governance and refresh cycles in one workflow.</p>',
    ]);

    $result = $service->placeIntoHtml($html, []);

    expect($result['updated_html'])->not->toContain('Related article 1')
        ->and($result['updated_html'])->not->toContain('explore resources like')
        ->and($result['updated_html'])->toContain('A strong content operation connects strategy');
});

it('places all relevant links inline without adding a related reading block', function () {
    $service = new InternalLinkPlacementService();

    $html = implode("\n", [
        '<p>Strong content clusters help teams coordinate pillar pages and supporting articles.</p>',
        '<p>Editorial workflow automation reduces handoffs and keeps publishing cadence predictable.</p>',
    ]);

    $result = $service->placeIntoHtml($html, [
        [
            'target_url' => 'https://example.com/content-clusters',
            'anchor_text' => 'content clusters',
            'title' => 'Content clusters',
        ],
        [
            'target_url' => 'https://example.com/editorial-workflow-automation',
            'anchor_text' => 'Editorial workflow automation',
            'title' => 'Editorial workflow automation',
        ],
    ]);

    expect($result['updated_html'])->toContain('<a href="https://example.com/content-clusters">content clusters</a>')
        ->and($result['updated_html'])->toContain('<a href="https://example.com/editorial-workflow-automation">Editorial workflow automation</a>')
        ->and($result['updated_html'])->not->toContain('Related reading:');
});

it('does not add a fallback block when at least one link was placed inline', function () {
    $service = new InternalLinkPlacementService();

    $html = '<p>Governance workflows help teams assign ownership before launch.</p>';

    $result = $service->placeIntoHtml($html, [
        [
            'target_url' => 'https://example.com/governance-workflows',
            'anchor_text' => 'Governance workflows',
            'title' => 'Governance workflows',
        ],
        [
            'target_url' => 'https://example.com/content-refresh-automation',
            'anchor_text' => 'content refresh automation',
            'title' => 'Content refresh automation',
        ],
    ]);

    expect($result['updated_html'])->toContain('<a href="https://example.com/governance-workflows">Governance workflows</a>')
        ->and($result['updated_html'])->not->toContain('Related reading:')
        ->and($result['updated_html'])->not->toContain('https://example.com/content-refresh-automation')
        ->and(substr_count($result['updated_html'], 'https://example.com/governance-workflows'))->toBe(1);
});

it('adds a compact fallback block when no links can be placed inline', function () {
    $service = new InternalLinkPlacementService();

    $html = '<p>This article talks about a broader operating model without exact anchors.</p>';

    $result = $service->placeIntoHtml($html, [
        [
            'target_url' => 'https://example.com/content-refresh-automation',
            'anchor_text' => 'content refresh automation',
            'title' => 'Content refresh automation',
        ],
    ]);

    expect($result['updated_html'])->toContain('Related reading:')
        ->and($result['updated_html'])->toContain('https://example.com/content-refresh-automation');
});

it('does not insert links that point to the current article url', function () {
    $service = new InternalLinkPlacementService();

    $html = '<p>Agentic AI execution needs a practical framework before teams scale workflows.</p>';

    $result = $service->placeIntoHtml($html, [
        [
            'target_url' => '/en/blog/a-practical-framework-for-agentic-ai-execution',
            'anchor_text' => 'practical framework',
            'title' => 'A Practical Framework for Agentic AI Execution',
        ],
    ], [
        'https://argusly.com/en/blog/a-practical-framework-for-agentic-ai-execution',
    ]);

    expect($result['updated_html'])->not->toContain('href="/en/blog/a-practical-framework-for-agentic-ai-execution"')
        ->and($result['updated_html'])->not->toContain('Related reading:')
        ->and($result['inline_links'])->toBe([])
        ->and($result['fallback_links'])->toBe([]);
});

it('preserves existing manual links while inserting new internal links', function () {
    $service = new InternalLinkPlacementService();

    $html = implode("\n", [
        '<p>Teams can review the <a href="https://docs.example.com/manual-playbook">manual playbook</a> before rollout.</p>',
        '<p>An editorial workflow checklist keeps handoffs visible across the team.</p>',
    ]);

    $result = $service->placeIntoHtml($html, [
        [
            'target_url' => 'https://example.com/editorial-workflow-checklist',
            'anchor_text' => 'editorial workflow checklist',
            'title' => 'Editorial workflow checklist',
        ],
    ]);

    expect($result['updated_html'])->toContain('https://docs.example.com/manual-playbook')
        ->and($result['updated_html'])->toContain('<a href="https://example.com/editorial-workflow-checklist">editorial workflow checklist</a>');
});

it('supports multilingual article bodies without adding placeholder labels', function () {
    $service = new InternalLinkPlacementService();

    $html = implode("\n", [
        '<p>Een sterk contentproces verbindt content clusters met governance workflows en duidelijke eigenaarschap.</p>',
        '<p>Teams plannen daarna vaste refreshmomenten voor publicaties.</p>',
    ]);

    $result = $service->placeIntoHtml($html, [
        [
            'target_url' => 'https://example.com/nl/blog/content-clusters',
            'anchor_text' => 'content clusters',
            'title' => 'Content clusters',
        ],
        [
            'target_url' => 'https://example.com/nl/blog/governance-workflows',
            'anchor_text' => 'governance workflows',
            'title' => 'Governance workflows',
        ],
    ]);

    expect($result['updated_html'])->toContain('https://example.com/nl/blog/content-clusters')
        ->and($result['updated_html'])->not->toContain('https://example.com/nl/blog/governance-workflows')
        ->and($result['updated_html'])->not->toContain('Related article')
        ->and($result['updated_html'])->not->toContain('Read more');
});

it('places anchors at the correct position after multibyte characters', function () {
    $service = new InternalLinkPlacementService();

    $html = '<p>This article explains what the shift van seo → geo → ai visibility means in practice.</p>';

    $result = $service->placeIntoHtml($html, [
        [
            'target_url' => 'https://example.com/ai-visibility',
            'anchor_text' => 'AI visibility',
            'title' => 'AI visibility',
        ],
    ]);

    expect($result['updated_html'])->toContain('geo → <a href="https://example.com/ai-visibility">ai visibility</a> means')
        ->and($result['updated_html'])->not->toContain('ai v<a')
        ->and($result['updated_html'])->not->toContain('</a>ns');
});

it('does not place anchors inside hyphenated compounds', function () {
    $service = new InternalLinkPlacementService();

    $html = '<p>De KPI draait om AI-zichtbaarheid en betere antwoordlagen.</p>';

    $result = $service->placeIntoHtml($html, [
        [
            'target_url' => 'https://example.com/zichtbaarheid',
            'anchor_text' => 'zichtbaarheid',
            'title' => 'zichtbaarheid',
        ],
    ]);

    expect($result['updated_html'])->toContain('AI-zichtbaarheid')
        ->and($result['updated_html'])->not->toContain('AI-<a href="https://example.com/zichtbaarheid">zichtbaarheid</a>');
});
