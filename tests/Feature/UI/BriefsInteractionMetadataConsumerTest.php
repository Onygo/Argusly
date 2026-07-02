<?php

use App\Http\Controllers\App\AppBriefsController;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Interaction\Providers\AppContentInteractionProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('keeps the briefs index title link text and href authoritative from the existing route output', function (): void {
    $context = makeBriefsInteractionMetadataContext();
    $brief = $context['briefs'][0];

    $response = $this->actingAs($context['user'])
        ->get(briefsInteractionMetadataIndexUrl());

    $response->assertOk()
        ->assertSee('Metadata brief 1')
        ->assertSee('href="'.route('app.briefs.show', $brief).'"', false);

    $data = $response->original->getData();
    expect($data['interactionResourcesByKey'])->toHaveKey('brief:'.$brief->id)
        ->and($data['interactionResourcesByKey']['brief:'.$brief->id]['key'])->toBe('brief:'.$brief->id)
        ->and($data['interactionActionsByKey']['brief:'.$brief->id])->toHaveKey(AppContentInteractionProvider::ACTION_BRIEF_OPEN);
});

it('renders an additive brief inspect drawer trigger with canonical href fallback', function (): void {
    $context = makeBriefsInteractionMetadataContext();
    $brief = $context['briefs'][0];
    $briefUrl = route('app.briefs.show', $brief);

    $response = $this->actingAs($context['user'])
        ->get(briefsInteractionMetadataIndexUrl());

    $response->assertOk()
        ->assertSee('<a class="text-textPrimary hover:underline" href="'.$briefUrl.'">Metadata brief 1</a>', false);

    $html = $response->getContent();
    preg_match('/<a\b(?=[^>]*data-drawer-trigger="button")(?=[^>]*data-drawer-target="brief\.inspect")[^>]*>.*?<\/a>/s', $html, $matches);
    $drawerTrigger = $matches[0] ?? '';

    expect($drawerTrigger)->not->toBe('')
        ->and(briefsInteractionHtmlAttribute($drawerTrigger, 'href'))->toBe($briefUrl)
        ->and(briefsInteractionHtmlAttribute($drawerTrigger, 'role'))->toBe('button')
        ->and(briefsInteractionHtmlAttribute($drawerTrigger, 'data-drawer-mode'))->toBe('inspect')
        ->and(briefsInteractionHtmlAttribute($drawerTrigger, 'data-drawer-resource-type'))->toBe('brief')
        ->and(briefsInteractionHtmlAttribute($drawerTrigger, 'data-drawer-resource-key'))->toBe('brief:'.$brief->id)
        ->and(briefsInteractionHtmlAttribute($drawerTrigger, 'data-drawer-resource-id'))->toBe((string) $brief->id)
        ->and(briefsInteractionHtmlAttribute($drawerTrigger, 'data-drawer-action-key'))->toBe(AppContentInteractionProvider::ACTION_BRIEF_OPEN)
        ->and(briefsInteractionHtmlAttribute($drawerTrigger, 'data-progressive-enhancement'))->toBe('true')
        ->and(briefsInteractionHtmlAttribute($drawerTrigger, 'data-command-palette-ready'))->toBe('true')
        ->and(trim(strip_tags($drawerTrigger)))->toBe('Inspect');

    $payload = json_decode(html_entity_decode((string) briefsInteractionHtmlAttribute($drawerTrigger, 'data-drawer-payload')), true);

    $actionMetadata = json_encode([
        'target_action_key' => $payload['target']['action_key'] ?? null,
        'action' => $payload['action'] ?? [],
        'footer_actions' => $payload['footer_actions'] ?? [],
        'available_actions' => $payload['resource']['available_actions'] ?? [],
    ]);

    expect($payload)
        ->toBeArray()
        ->and($payload['href'])->toBe($briefUrl)
        ->and($payload['target'])->toMatchArray([
            'target' => 'brief.inspect',
            'mode' => 'inspect',
            'resource_type' => 'brief',
            'resource_key' => 'brief:'.$brief->id,
            'resource_id' => $brief->id,
            'action_key' => AppContentInteractionProvider::ACTION_BRIEF_OPEN,
        ])
        ->and($payload['action'])->toMatchArray([
            'key' => AppContentInteractionProvider::ACTION_BRIEF_OPEN,
            'method' => 'GET',
            'execution_mode' => 'link',
        ])
        ->and($payload['resource']['available_actions'])->toBe([AppContentInteractionProvider::ACTION_BRIEF_OPEN])
        ->and($actionMetadata)->not->toContain('POST')
        ->and($actionMetadata)->not->toContain('generate')
        ->and($actionMetadata)->not->toContain('archive')
        ->and($actionMetadata)->not->toContain('enhance')
        ->and($actionMetadata)->not->toContain('compare')
        ->and($actionMetadata)->not->toContain('apply')
        ->and($actionMetadata)->not->toContain('reject')
        ->and($actionMetadata)->not->toContain('create-draft');
});

it('does not expose unauthorized brief resources in the consumer metadata maps', function (): void {
    $context = makeBriefsInteractionMetadataContext();
    $other = makeBriefsInteractionMetadataContext(
        organizationName: 'Other Brief Metadata Org',
        userEmail: 'other-brief-metadata@example.com',
        briefCount: 1,
        briefTitlePrefix: 'Unauthorized brief'
    );

    $response = $this->actingAs($context['user'])
        ->get(briefsInteractionMetadataIndexUrl());

    $response->assertOk()
        ->assertSee('Metadata brief 1')
        ->assertDontSee('Unauthorized brief 1');

    $data = $response->original->getData();
    expect($data['interactionResourcesByKey'])->toHaveKey('brief:'.$context['briefs'][0]->id)
        ->and($data['interactionResourcesByKey'])->not->toHaveKey('brief:'.$other['briefs'][0]->id)
        ->and($data['interactionActionsByKey'])->not->toHaveKey('brief:'.$other['briefs'][0]->id);
});

it('keeps primary actions filters reset links and empty states literal when metadata maps are empty', function (): void {
    $context = makeBriefsInteractionMetadataContext(briefCount: 0);

    $response = $this->actingAs($context['user'])
        ->get(briefsInteractionMetadataIndexUrl());

    $response->assertOk()
        ->assertSee('href="'.route('app.briefs.create').'"', false)
        ->assertSee('New Brief')
        ->assertSee('href="'.route('app.briefs').'"', false)
        ->assertSee('No briefs yet')
        ->assertSee('Create your first brief')
        ->assertSee('href="'.route('app.content.batches.create').'"', false)
        ->assertSee('Generate multiple articles');

    $data = $response->original->getData();
    expect($data['briefs']->total())->toBe(0)
        ->and($data['interactionResourcesByKey'])->toBe([])
        ->and($data['interactionActionsByKey'])->toBe([]);

    $filteredResponse = $this->actingAs($context['user'])
        ->get(briefsInteractionMetadataIndexUrl(['q' => 'nothing-matches']));

    $filteredResponse->assertOk()
        ->assertSee('name="q" value="nothing-matches"', false)
        ->assertSee('No briefs match your filters')
        ->assertSee('Clear filters')
        ->assertSee('href="'.route('app.briefs').'"', false);

    $filteredData = $filteredResponse->original->getData();
    expect($filteredData['interactionResourcesByKey'])->toBe([])
        ->and($filteredData['interactionActionsByKey'])->toBe([]);
});

it('keeps pagination and filters authoritative while resolving metadata for the current page only', function (): void {
    $context = makeBriefsInteractionMetadataContext(briefCount: 21, status: 'ready_for_generation');
    $otherSite = makeBriefsInteractionMetadataSite($context['workspace'], 'Filtered-out Brief Site');
    $otherBrief = makeBriefsInteractionMetadataBrief($otherSite, title: 'Filtered-out brief', status: 'ready_for_generation');

    $response = $this->actingAs($context['user'])
        ->get(briefsInteractionMetadataIndexUrl([
            'site' => (string) $context['site']->id,
            'status' => 'ready_for_generation',
        ]));

    $response->assertOk()
        ->assertSee('Metadata brief')
        ->assertDontSee('href="'.route('app.briefs.show', $otherBrief).'"', false)
        ->assertSee('page=2', false)
        ->assertSee('site='.$context['site']->id, false)
        ->assertSee('value="'.$context['site']->id.'" selected', false)
        ->assertSee('value="ready_for_generation" selected', false)
        ->assertSee('href="'.route('app.briefs').'"', false);

    $data = $response->original->getData();
    expect($data['briefs']->total())->toBe(21)
        ->and($data['briefs']->count())->toBe(20)
        ->and($data['briefs']->pluck('id')->map(fn ($id): string => (string) $id)->all())->not->toContain((string) $otherBrief->id)
        ->and($data['interactionResourcesByKey'])->toHaveCount(20)
        ->and($data['interactionResourcesByKey'])->not->toHaveKey('brief:'.$otherBrief->id);
});

it('resolves brief metadata without lazy-loading row relations', function (): void {
    $context = makeBriefsInteractionMetadataContext(briefCount: 3);
    $context['user']->load('organization');

    Model::preventLazyLoading();

    try {
        $view = $this->actingAs($context['user'])
            ->get(briefsInteractionMetadataIndexUrl())
            ->assertOk()
            ->original;

        expect($view->getData()['interactionResourcesByKey'])->toHaveCount(3);
    } finally {
        Model::preventLazyLoading(false);
    }
});

function briefsInteractionMetadataIndexUrl(array $query = []): string
{
    if (! Route::has('interaction-metadata.briefs.index')) {
        Route::get('/__interaction-metadata/briefs', [AppBriefsController::class, 'index'])
            ->middleware('web')
            ->name('interaction-metadata.briefs.index');
    }

    $url = '/__interaction-metadata/briefs';

    if ($query !== []) {
        $url .= '?'.http_build_query($query);
    }

    return $url;
}

function briefsInteractionHtmlAttribute(string $html, string $attribute): ?string
{
    preg_match('/\s'.preg_quote($attribute, '/').'="([^"]*)"/', $html, $matches);

    return isset($matches[1]) ? html_entity_decode($matches[1]) : null;
}

function makeBriefsInteractionMetadataContext(
    string $organizationName = 'Brief Metadata Org',
    string $userEmail = 'brief-metadata@example.com',
    int $briefCount = 1,
    string $briefTitlePrefix = 'Metadata brief',
    string $status = 'draft',
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

    $site = makeBriefsInteractionMetadataSite($workspace, $organizationName.' Site');

    $user = User::query()->create([
        'name' => $organizationName.' User',
        'email' => $userEmail,
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $briefs = $briefCount <= 0
        ? []
        : collect(range(1, $briefCount))
            ->map(fn (int $number): Brief => makeBriefsInteractionMetadataBrief(
                $site,
                title: $briefTitlePrefix.' '.$number,
                status: $status,
                createdAt: now()->subMinutes($number)
            ))
            ->all();

    return compact('organization', 'workspace', 'site', 'user', 'briefs');
}

function makeBriefsInteractionMetadataSite(Workspace $workspace, string $name): ClientSite
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

function makeBriefsInteractionMetadataBrief(
    ClientSite $site,
    string $title = 'Metadata brief',
    string $status = 'draft',
    mixed $createdAt = null,
): Brief {
    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $site->workspace_id,
        'client_site_id' => $site->id,
        'title' => $title.' content',
        'primary_keyword' => 'metadata brief',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'external_key' => (string) Str::uuid(),
        'generation_mode' => 'balanced',
        'preferred_length' => 'medium',
    ]);

    return Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => $status,
        'title' => $title,
        'language' => 'en',
        'content_type' => 'blog',
        'primary_keyword' => 'metadata brief',
        'source' => 'client_ui',
        'created_at' => $createdAt ?? now(),
        'updated_at' => $createdAt ?? now(),
    ]);
}
