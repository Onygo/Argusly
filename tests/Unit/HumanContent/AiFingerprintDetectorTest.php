<?php

use App\Services\HumanContent\AiFingerprintDetector;

it('detects generic AI patterns in English content', function (): void {
    $result = app(AiFingerprintDetector::class)->detect(
        '<h1>Introduction</h1><p>In today\'s digital landscape, it is important to note that teams can unlock the power of a robust solution.</p><h2>Main Section</h2><p>Moreover, this game changer helps businesses stay ahead of the curve.</p><h2>Conclusion</h2><p>In conclusion, get started today and learn more.</p>',
        'en',
    );

    $types = collect($result['findings'])->pluck('type')->all();

    expect($result['score'])->toBeGreaterThanOrEqual(45)
        ->and($types)->toContain('generic_headings')
        ->and($types)->toContain('chatgpt_vocabulary')
        ->and($types)->toContain('marketing_cliches')
        ->and($types)->toContain('predictable_openings')
        ->and($types)->toContain('predictable_endings');
});

it('detects uniform paragraph rhythm', function (): void {
    $paragraph = 'Teams need clearer workflows because generic review steps reduce editorial confidence quickly.';
    $html = '<h1>Workflow rhythm</h1>'
        . '<p>' . $paragraph . '</p>'
        . '<p>' . $paragraph . '</p>'
        . '<p>' . $paragraph . '</p>'
        . '<p>' . $paragraph . '</p>';

    $result = app(AiFingerprintDetector::class)->detect($html, 'en');

    expect(collect($result['findings'])->pluck('type')->all())->toContain('uniform_paragraph_lengths');
});

it('detects bullet and numbered list overuse', function (): void {
    $html = '<h1>List-heavy article</h1>'
        . str_repeat('<ul><li>One point</li><li>Another point</li></ul>', 4)
        . str_repeat('<ol><li>Step one</li><li>Step two</li></ol>', 3);

    $result = app(AiFingerprintDetector::class)->detect($html, 'en');
    $types = collect($result['findings'])->pluck('type')->all();

    expect($types)->toContain('too_many_bullet_lists')
        ->and($types)->toContain('too_many_numbered_lists');
});

it('supports Dutch phrase libraries', function (): void {
    $result = app(AiFingerprintDetector::class)->detect(
        '<h1>Inleiding</h1><p>In het huidige digitale landschap is het belangrijk om op te merken dat bedrijven een naadloze ervaring nodig hebben.</p><h2>Conclusie</h2><p>Kortom, boek een demo en zet de volgende stap.</p>',
        'nl',
    );

    $types = collect($result['findings'])->pluck('type')->all();

    expect($types)->toContain('generic_headings')
        ->and($types)->toContain('chatgpt_vocabulary')
        ->and($types)->toContain('predictable_openings')
        ->and($types)->toContain('predictable_endings');
});

it('produces structured findings usable by humanization workflows', function (): void {
    $result = app(AiFingerprintDetector::class)->detect(
        '<h1>Introduction</h1><p>In today\'s digital landscape, it goes without saying that teams should leverage robust solutions.</p>',
        'en',
    );

    expect($result)->toHaveKeys(['score', 'severity', 'findings', 'humanization_actions'])
        ->and($result['humanization_actions'])->not->toBeEmpty();

    foreach ($result['findings'] as $finding) {
        expect($finding)->toHaveKeys([
            'type',
            'severity',
            'message',
            'evidence',
            'count',
            'recommendation',
            'humanization_action',
        ]);
    }
});
