<?php

use App\Models\Brief;
use App\Services\Briefs\BriefPromptBuilder;

it('includes key brief fields in the generated prompt and draft meta', function () {
    $brief = new Brief([
        'title' => 'AI Content Governance',
        'language' => 'nl',
        'content_type' => 'blog',
        'primary_keyword' => 'AI content governance',
        'secondary_keywords' => ['compliance', 'workflow'],
        'target_audience' => 'Marketing managers',
        'funnel_stage' => 'consideration',
        'search_intent' => 'informational',
        'tone_of_voice' => 'zakelijk, helder',
        'unique_angle' => 'focus op governance',
        'key_points' => ['punt 1', 'punt 2'],
        'call_to_action' => 'Bekijk de demo',
        'notes' => 'Use practical examples.',
        'desired_length_min' => 1200,
        'desired_length_max' => 1500,
    ]);

    $builder = new BriefPromptBuilder();

    $prompt = $builder->buildPrompt($brief);
    $meta = $builder->buildDraftMeta($brief);

    expect($prompt)->toContain('Title: AI Content Governance');
    expect($prompt)->toContain('Target audience: Marketing managers');
    expect($prompt)->toContain('Search intent: informational');
    expect($prompt)->toContain('Secondary keywords: compliance, workflow');
    expect($prompt)->toContain('Call to action: Bekijk de demo');

    expect($meta['language'])->toBe('nl');
    expect($meta['primary_keyword'])->toBe('AI content governance');
    expect($meta['secondary_keywords'])->toBe(['compliance', 'workflow']);
    expect($meta['preferred_length'])->toBe('long');
    expect($meta['key_points'])->toBe(['punt 1', 'punt 2']);
    expect($meta)->not->toHaveKey('structure');
    expect((string) $meta['notes'])->toContain('BRIEF CONTEXT');
});
