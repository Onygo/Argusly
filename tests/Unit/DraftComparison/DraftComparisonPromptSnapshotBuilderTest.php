<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Draft;
use App\Models\DraftComparison;
use App\Models\DraftComparisonVariant;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\DraftComparison\DraftComparisonPromptSnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('builds stable shared prompt snapshot inputs across variants while provider/model differ', function () {
    $organization = Organization::query()->create([
        'name' => 'Prompt Snapshot Org',
        'slug' => 'prompt-snapshot-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Prompt Snapshot Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Prompt Snapshot Site',
        'site_url' => 'https://prompt-snapshot.example.com',
        'allowed_domains' => ['prompt-snapshot.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'status' => 'done',
        'source' => 'client_ui',
        'progress' => 1,
        'title' => 'Prompt fairness brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'primary_keyword' => 'fair compare keyword',
        'secondary_keywords' => ['keyword a', 'keyword b'],
        'target_audience' => 'Marketing leaders',
        'tone_of_voice' => 'Practical',
        'search_intent' => 'informational',
        'funnel_stage' => 'consideration',
        'key_points' => ['Point A', 'Point B'],
        'call_to_action' => 'Book a demo',
    ]);

    $comparison = DraftComparison::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'mode' => 'compare_two',
        'status' => DraftComparison::STATUS_PROCESSING,
    ]);

    $variantA = DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'openai',
        'model_key' => 'gpt-4.1-mini',
        'status' => DraftComparisonVariant::STATUS_PROCESSING,
    ]);

    $variantB = DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'anthropic',
        'model_key' => 'claude-3-5-sonnet-latest',
        'status' => DraftComparisonVariant::STATUS_PROCESSING,
    ]);

    $baseMeta = [
        'language' => 'en',
        'primary_keyword' => 'fair compare keyword',
        'secondary_keywords' => ['keyword a', 'keyword b'],
        'tone' => 'Practical',
        'audience' => 'Marketing leaders',
        'notes' => 'Use clear examples.',
        'structure' => ['Opening', 'Main section', 'Practical examples', 'Conclusion'],
        'funnel_stage' => 'consideration',
        'search_intent' => 'informational',
        'key_points' => ['Point A', 'Point B'],
        'call_to_action' => 'Book a demo',
        'requested_max_output_tokens' => 10000,
        'generation_type' => 'article',
        'draft_compare' => [
            'comparison_id' => (string) $comparison->id,
            'is_hybrid' => false,
            'comparison_credit_managed' => true,
        ],
    ];

    $draftA = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => 'queued',
        'title' => 'Prompt fairness brief',
        'output_type' => 'kb_article',
        'credit_cost' => 12,
        'meta' => array_merge($baseMeta, [
            'generation_provider_override' => 'openai',
            'generation_model_override' => 'gpt-4.1-mini',
        ]),
    ]);

    $draftB = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => 'queued',
        'title' => 'Prompt fairness brief',
        'output_type' => 'kb_article',
        'credit_cost' => 12,
        'meta' => array_merge($baseMeta, [
            'generation_provider_override' => 'anthropic',
            'generation_model_override' => 'claude-3-5-sonnet-latest',
        ]),
    ]);

    $builder = app(DraftComparisonPromptSnapshotBuilder::class);

    $snapshotA = $builder->buildForVariant($comparison, $variantA, $draftA);
    $snapshotB = $builder->buildForVariant($comparison, $variantB, $draftB);

    expect((string) data_get($snapshotA, 'provider_key'))->toBe('openai')
        ->and((string) data_get($snapshotB, 'provider_key'))->toBe('anthropic')
        ->and((string) data_get($snapshotA, 'model_key'))->toBe('gpt-4.1-mini')
        ->and((string) data_get($snapshotB, 'model_key'))->toBe('claude-3-5-sonnet-latest')
        ->and((string) data_get($snapshotA, 'shared_inputs_hash'))->toBe((string) data_get($snapshotB, 'shared_inputs_hash'))
        ->and((string) data_get($snapshotA, 'shared_inputs.brief.id'))->toBe((string) $brief->id)
        ->and((string) data_get($snapshotA, 'shared_inputs.brief.title'))->toBe('Prompt fairness brief')
        ->and((string) data_get($snapshotA, 'shared_inputs.brief.language'))->toBe('en')
        ->and((string) data_get($snapshotA, 'shared_inputs.voice.tone'))->toBe('Practical')
        ->and((string) data_get($snapshotA, 'shared_inputs.keywords.primary'))->toBe('fair compare keyword');

    $userPrompt = (string) data_get($snapshotA, 'generation_payload.user', '');
    expect($userPrompt)->toContain('"draft_comparison_id"')
        ->and($userPrompt)->not->toContain('"draft_id"');
});
