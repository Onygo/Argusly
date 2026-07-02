<?php

use App\Enums\ResearchProjectStatus;
use App\Http\Controllers\App\AppResearchController;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\ResearchProject;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Support\Interaction\Providers\AppResearchInteractionProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('keeps research project title and open links authoritative from existing route output', function (): void {
    $context = makeResearchInteractionMetadataContext();
    $project = $context['projects'][0];

    $response = $this->actingAs($context['user'])
        ->get(researchInteractionMetadataIndexUrl(['workspace_id' => (string) $context['workspace']->id]));

    $response->assertOk()
        ->assertSee('Metadata research 1')
        ->assertSee('href="'.route('app.research.show', $project).'"', false)
        ->assertSee('Open');

    $data = $response->original->getData();
    expect($data['interactionResourcesByKey'])->toHaveKey('research_project:'.$project->id)
        ->and($data['interactionResourcesByKey']['research_project:'.$project->id]['key'])->toBe('research_project:'.$project->id)
        ->and($data['interactionActionsByKey']['research_project:'.$project->id])->toHaveKey(AppResearchInteractionProvider::ACTION_RESEARCH_PROJECT_OPEN);
});

it('keeps the primary create link literal while resolving create action metadata for creators', function (): void {
    $context = makeResearchInteractionMetadataContext(projectCount: 0);

    $response = $this->actingAs($context['user'])
        ->get(researchInteractionMetadataIndexUrl(['workspace_id' => (string) $context['workspace']->id]));

    $response->assertOk()
        ->assertSee('href="'.route('app.research.create', ['workspace_id' => $context['workspace']->id]).'"', false)
        ->assertSee('New research project')
        ->assertSee('No research projects yet');

    $data = $response->original->getData();
    expect($data['interactionResourcesByKey'])->toBe([])
        ->and($data['interactionActionsByKey']['app.research.index'])->toHaveKey(AppResearchInteractionProvider::ACTION_RESEARCH_PROJECT_CREATE);
});

it('keeps start and rerun as POST forms and does not expose start as metadata', function (): void {
    $context = makeResearchInteractionMetadataContext(projectCount: 0);
    $draftProject = makeResearchInteractionMetadataProject(
        $context['workspace'],
        $context['site'],
        $context['brief'],
        'Draft research',
        ResearchProjectStatus::DRAFT
    );
    $failedProject = makeResearchInteractionMetadataProject(
        $context['workspace'],
        $context['site'],
        $context['brief'],
        'Failed research',
        ResearchProjectStatus::FAILED
    );

    $response = $this->actingAs($context['user'])
        ->get(researchInteractionMetadataIndexUrl(['workspace_id' => (string) $context['workspace']->id]));

    $response->assertOk()
        ->assertSee('method="POST"', false)
        ->assertSee('action="'.route('app.research.start', $draftProject).'"', false)
        ->assertSee('action="'.route('app.research.start', $failedProject).'"', false)
        ->assertSee('Start')
        ->assertSee('Rerun')
        ->assertSee('name="force" value="1"', false);

    $data = $response->original->getData();
    $resolvedActionKeys = collect($data['interactionActionsByKey'])
        ->flatMap(fn (array $actions): array => array_keys($actions))
        ->all();

    expect($resolvedActionKeys)->toContain(AppResearchInteractionProvider::ACTION_RESEARCH_PROJECT_OPEN)
        ->and($resolvedActionKeys)->toContain(AppResearchInteractionProvider::ACTION_RESEARCH_PROJECT_CREATE)
        ->and($resolvedActionKeys)->not->toContain('app.research.start');
});

it('does not expose unauthorized research project resources in the consumer metadata maps', function (): void {
    $context = makeResearchInteractionMetadataContext();
    $other = makeResearchInteractionMetadataContext(
        organizationName: 'Other Research Metadata Org',
        userEmail: 'other-research-metadata@example.com',
        projectCount: 1,
        projectTitlePrefix: 'Unauthorized research'
    );

    $response = $this->actingAs($context['user'])
        ->get(researchInteractionMetadataIndexUrl(['workspace_id' => (string) $context['workspace']->id]));

    $response->assertOk()
        ->assertSee('Metadata research 1')
        ->assertDontSee('Unauthorized research 1');

    $data = $response->original->getData();
    expect($data['interactionResourcesByKey'])->toHaveKey('research_project:'.$context['projects'][0]->id)
        ->and($data['interactionResourcesByKey'])->not->toHaveKey('research_project:'.$other['projects'][0]->id)
        ->and($data['interactionActionsByKey'])->not->toHaveKey('research_project:'.$other['projects'][0]->id);
});

it('keeps pagination authoritative while resolving metadata for the current page only', function (): void {
    $context = makeResearchInteractionMetadataContext(projectCount: 21);

    $response = $this->actingAs($context['user'])
        ->get(researchInteractionMetadataIndexUrl(['workspace_id' => (string) $context['workspace']->id]));

    $response->assertOk()
        ->assertSee('Metadata research')
        ->assertSee('page=2', false);

    $data = $response->original->getData();
    expect($data['projects']->total())->toBe(21)
        ->and($data['projects']->count())->toBe(20)
        ->and($data['interactionResourcesByKey'])->toHaveCount(20);
});

it('resolves research metadata without lazy-loading row relations', function (): void {
    $context = makeResearchInteractionMetadataContext(projectCount: 3);
    $context['user']->load('organization');

    Model::preventLazyLoading();

    try {
        $view = $this->actingAs($context['user'])
            ->get(researchInteractionMetadataIndexUrl(['workspace_id' => (string) $context['workspace']->id]))
            ->assertOk()
            ->original;

        expect($view->getData()['interactionResourcesByKey'])->toHaveCount(3);
    } finally {
        Model::preventLazyLoading(false);
    }
});

function researchInteractionMetadataIndexUrl(array $query = []): string
{
    if (! Route::has('interaction-metadata.research.index')) {
        Route::get('/__interaction-metadata/research', [AppResearchController::class, 'index'])
            ->middleware('web')
            ->name('interaction-metadata.research.index');
    }

    $url = '/__interaction-metadata/research';

    if ($query !== []) {
        $url .= '?'.http_build_query($query);
    }

    return $url;
}

function makeResearchInteractionMetadataContext(
    string $organizationName = 'Research Metadata Org',
    string $userEmail = 'research-metadata@example.com',
    int $projectCount = 1,
    string $projectTitlePrefix = 'Metadata research',
): array {
    $organization = Organization::query()->create([
        'name' => $organizationName,
        'slug' => Str::slug($organizationName).'-'.Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => $organizationName.' BV',
        'billing_address_line1' => 'Metadata Street 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => $organizationName.' Workspace',
        'organization_id' => $organization->id,
    ]);

    setResearchInteractionMetadataEntitlement($workspace, 'research_enabled', 'bool', true);

    $site = makeResearchInteractionMetadataSite($workspace, $organizationName.' Site');
    $brief = makeResearchInteractionMetadataBrief($site, $organizationName.' Brief');

    $user = User::query()->create([
        'name' => $organizationName.' User',
        'email' => $userEmail,
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $projects = $projectCount <= 0
        ? []
        : collect(range(1, $projectCount))
            ->map(fn (int $number): ResearchProject => makeResearchInteractionMetadataProject(
                $workspace,
                $site,
                $brief,
                $projectTitlePrefix.' '.$number,
                ResearchProjectStatus::COMPLETED,
                now()->subMinutes($number)
            ))
            ->all();

    return compact('organization', 'workspace', 'site', 'brief', 'user', 'projects');
}

function makeResearchInteractionMetadataSite(Workspace $workspace, string $name): ClientSite
{
    return ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => $name,
        'site_url' => 'https://'.Str::slug($name).'.example.com',
        'allowed_domains' => [Str::slug($name).'.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);
}

function makeResearchInteractionMetadataBrief(ClientSite $site, string $title): Brief
{
    return Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'status' => 'done',
        'source' => 'client_ui',
        'progress' => 1,
        'title' => $title,
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);
}

function makeResearchInteractionMetadataProject(
    Workspace $workspace,
    ClientSite $site,
    Brief $brief,
    string $name,
    ResearchProjectStatus $status = ResearchProjectStatus::COMPLETED,
    mixed $createdAt = null,
): ResearchProject {
    return ResearchProject::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'name' => $name,
        'status' => $status,
        'target_keywords' => ['metadata research'],
        'config' => ['created_from' => 'metadata-test'],
        'created_at' => $createdAt ?? now(),
        'updated_at' => $createdAt ?? now(),
    ]);
}

function setResearchInteractionMetadataEntitlement(Workspace $workspace, string $featureKey, string $valueType, mixed $value): void
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
