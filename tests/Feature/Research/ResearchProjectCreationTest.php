<?php

use App\Actions\Research\CreateResearchProjectAction;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\ResearchProject;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates a research project with normalized unique sources', function () {
    [$user, $workspace, $site, $brief] = makeResearchCreationContext();

    setResearchEntitlement($workspace, 'research_enabled', 'bool', true);
    setResearchEntitlement($workspace, 'research_max_sources_per_project', 'int', 5);

    $project = app(CreateResearchProjectAction::class)->execute($user, [
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'brief_id' => (string) $brief->id,
        'name' => 'Q2 AI Market Research',
        'target_keywords' => ['ai agents', 'laravel'],
        'source_urls' => [
            'example.com/market',
            'https://example.com/market',
            'https://example.com/stats',
        ],
    ]);

    $project->refresh()->load('sources');

    expect((string) $project->workspace_id)->toBe((string) $workspace->id)
        ->and((string) $project->name)->toBe('Q2 AI Market Research')
        ->and($project->sources)->toHaveCount(2)
        ->and($project->sources->pluck('url')->all())->toContain('https://example.com/market', 'https://example.com/stats');
});

it('denies project creation when research feature is disabled', function () {
    [$user, $workspace] = makeResearchCreationContext('research-create-denied');

    setResearchEntitlement($workspace, 'research_enabled', 'bool', false);

    expect(fn () => app(CreateResearchProjectAction::class)->execute($user, [
        'workspace_id' => (string) $workspace->id,
        'name' => 'Blocked project',
        'source_urls' => ['https://example.com/a'],
    ]))->toThrow(AuthorizationException::class);
});

it('enforces workspace organization isolation when creating projects', function () {
    [$user, $workspace] = makeResearchCreationContext('research-create-org-a');
    [, $foreignWorkspace, $foreignSite] = makeResearchCreationContext('research-create-org-b');

    setResearchEntitlement($workspace, 'research_enabled', 'bool', true);

    expect(fn () => app(CreateResearchProjectAction::class)->execute($user, [
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $foreignSite->id,
        'name' => 'Cross-org attempt',
        'source_urls' => ['https://example.com/a'],
    ]))->toThrow(RuntimeException::class, 'organization');

    expect(ResearchProject::query()->where('workspace_id', $foreignWorkspace->id)->count())->toBe(0);
});

it('rejects project creation when source count exceeds entitlement limit', function () {
    [$user, $workspace, $site] = makeResearchCreationContext('research-create-max');

    setResearchEntitlement($workspace, 'research_enabled', 'bool', true);
    setResearchEntitlement($workspace, 'research_max_sources_per_project', 'int', 1);

    expect(fn () => app(CreateResearchProjectAction::class)->execute($user, [
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'name' => 'Too many sources',
        'source_urls' => ['https://example.com/a', 'https://example.com/b'],
    ]))->toThrow(RuntimeException::class, 'Maximum 1 sources');
});

function makeResearchCreationContext(string $prefix = 'research-create'): array
{
    $organization = Organization::query()->create([
        'name' => 'Research Org',
        'slug' => $prefix . '-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Research Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Research Site',
        'site_url' => 'https://research.example.com',
        'allowed_domains' => ['research.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'status' => 'done',
        'source' => 'client_ui',
        'progress' => 1,
        'title' => 'Research Brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    $user = User::query()->create([
        'name' => 'Research Owner',
        'email' => $prefix . '+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    return [$user, $workspace, $site, $brief];
}

function setResearchEntitlement(Workspace $workspace, string $featureKey, string $valueType, mixed $value): void
{
    WorkspaceEntitlement::query()->updateOrCreate(
        [
            'workspace_id' => $workspace->id,
            'feature_key' => $featureKey,
        ],
        [
            'id' => (string) Str::uuid(),
            'organization_id' => $workspace->organization_id,
            'value_type' => $valueType,
            'value_bool' => $valueType === 'bool' ? (bool) $value : null,
            'value_int' => $valueType === 'int' ? (int) $value : null,
            'value_string' => $valueType === 'string' ? (string) $value : null,
            'value_json' => $valueType === 'json' ? (array) $value : null,
            'source' => 'manual',
            'effective_at' => now()->subMinute(),
            'expires_at' => null,
            'refreshed_at' => now(),
        ]
    );
}
