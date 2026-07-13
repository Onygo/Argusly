<?php

use App\Models\BrandVoice;
use App\Models\CompanyIntelligenceProfile;
use App\Services\BrandIntelligence\BrandIntelligenceContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds a compact approved brand intelligence snapshot for a workspace', function (): void {
    $profile = CompanyIntelligenceProfile::factory()->default()->create([
        'brand_key' => 'primary',
        'company_name' => 'Argusly',
        'positioning' => 'Governed agentic marketing for B2B teams.',
        'proof_points' => ['Audit trails', 'Approval workflows'],
        'icps' => ['B2B SaaS marketing teams'],
        'target_entities' => ['Argusly', 'Agentic Marketing OS'],
        'status' => CompanyIntelligenceProfile::STATUS_ACTIVE,
    ]);
    $workspace = $profile->workspace()->firstOrFail();

    BrandVoice::query()->create([
        'workspace_id' => $workspace->id,
        'organization_id' => $workspace->organization_id,
        'name' => 'Strategic clear voice',
        'tone_of_voice' => 'Clear, strategic and practical.',
        'writing_style' => 'Plain-spoken and evidence-led.',
        'preferred_terminology' => "agentic marketing\nAI visibility",
        'disallowed_terminology' => 'magic AI',
        'is_default' => true,
    ]);

    $snapshot = app(BrandIntelligenceContextService::class)->snapshotForWorkspace($workspace);

    expect($snapshot['available'])->toBeTrue()
        ->and($snapshot['schema_version'])->toBe('brand_intelligence.snapshot.v1')
        ->and(data_get($snapshot, 'company.name'))->toBe('Argusly')
        ->and(data_get($snapshot, 'company.positioning'))->toBe('Governed agentic marketing for B2B teams.')
        ->and(data_get($snapshot, 'audience.icps'))->toContain('B2B SaaS marketing teams')
        ->and(data_get($snapshot, 'proof.proof_points'))->toContain('Audit trails')
        ->and(data_get($snapshot, 'voice.preferred_terminology'))->toContain('agentic marketing')
        ->and(data_get($snapshot, 'voice.disallowed_terminology'))->toContain('magic AI')
        ->and(data_get($snapshot, 'entities.target_entities'))->toContain('Agentic Marketing OS')
        ->and(data_get($snapshot, 'sources.company_intelligence_profile_id'))->toBe($profile->id);
});

it('reports unavailable context when no approved brand sources exist', function (): void {
    $profile = CompanyIntelligenceProfile::factory()->create([
        'status' => CompanyIntelligenceProfile::STATUS_ARCHIVED,
    ]);

    $snapshot = app(BrandIntelligenceContextService::class)
        ->snapshotForWorkspace((string) $profile->workspace_id);

    expect($snapshot['available'])->toBeFalse()
        ->and($snapshot['sources'])->toBe([]);
});
