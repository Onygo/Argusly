<?php

use App\Http\Controllers\App\AppDraftsController;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Interaction\Providers\AppContentInteractionProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('keeps the drafts index title link text and href authoritative from the existing route output', function (): void {
    $context = makeDraftsInteractionMetadataContext();
    $draft = $context['drafts'][0];

    $response = $this->actingAs($context['user'])
        ->get(draftsInteractionMetadataIndexUrl());

    $response->assertOk()
        ->assertSee('Metadata draft 1')
        ->assertSee('href="'.route('app.drafts.show', $draft).'"', false);

    $data = $response->original->getData();
    expect($data['interactionResourcesByKey'])->toHaveKey('draft:'.$draft->id)
        ->and($data['interactionResourcesByKey']['draft:'.$draft->id]['key'])->toBe('draft:'.$draft->id)
        ->and($data['interactionActionsByKey']['draft:'.$draft->id])->toHaveKey(AppContentInteractionProvider::ACTION_DRAFT_OPEN);
});

it('renders an additive draft inspect drawer trigger with canonical href fallback', function (): void {
    $context = makeDraftsInteractionMetadataContext();
    $draft = $context['drafts'][0];
    $draftUrl = route('app.drafts.show', $draft);

    $response = $this->actingAs($context['user'])
        ->get(draftsInteractionMetadataIndexUrl());

    $response->assertOk()
        ->assertSee('<a class="text-textPrimary hover:underline" href="'.$draftUrl.'">Metadata draft 1</a>', false);

    $html = $response->getContent();
    preg_match('/<a\b(?=[^>]*data-drawer-trigger="button")(?=[^>]*data-drawer-target="draft\.inspect")[^>]*>.*?<\/a>/s', $html, $matches);
    $drawerTrigger = $matches[0] ?? '';

    expect($drawerTrigger)->not->toBe('')
        ->and(draftsInteractionHtmlAttribute($drawerTrigger, 'href'))->toBe($draftUrl)
        ->and(draftsInteractionHtmlAttribute($drawerTrigger, 'role'))->toBe('button')
        ->and(draftsInteractionHtmlAttribute($drawerTrigger, 'data-drawer-mode'))->toBe('inspect')
        ->and(draftsInteractionHtmlAttribute($drawerTrigger, 'data-drawer-resource-type'))->toBe('draft')
        ->and(draftsInteractionHtmlAttribute($drawerTrigger, 'data-drawer-resource-key'))->toBe('draft:'.$draft->id)
        ->and(draftsInteractionHtmlAttribute($drawerTrigger, 'data-drawer-resource-id'))->toBe((string) $draft->id)
        ->and(draftsInteractionHtmlAttribute($drawerTrigger, 'data-drawer-action-key'))->toBe(AppContentInteractionProvider::ACTION_DRAFT_OPEN)
        ->and(draftsInteractionHtmlAttribute($drawerTrigger, 'data-progressive-enhancement'))->toBe('true')
        ->and(draftsInteractionHtmlAttribute($drawerTrigger, 'data-command-palette-ready'))->toBe('true')
        ->and(trim(strip_tags($drawerTrigger)))->toBe('Inspect');

    $payload = json_decode(html_entity_decode((string) draftsInteractionHtmlAttribute($drawerTrigger, 'data-drawer-payload')), true);

    expect($payload)
        ->toBeArray()
        ->and($payload['href'])->toBe($draftUrl)
        ->and($payload['target'])->toMatchArray([
            'target' => 'draft.inspect',
            'mode' => 'inspect',
            'resource_type' => 'draft',
            'resource_key' => 'draft:'.$draft->id,
            'resource_id' => $draft->id,
            'action_key' => AppContentInteractionProvider::ACTION_DRAFT_OPEN,
        ])
        ->and($payload['action'])->toMatchArray([
            'key' => AppContentInteractionProvider::ACTION_DRAFT_OPEN,
            'method' => 'GET',
            'execution_mode' => 'link',
        ])
        ->and($payload['resource']['available_actions'])->toBe([AppContentInteractionProvider::ACTION_DRAFT_OPEN])
        ->and(json_encode($payload))->not->toContain('POST')
        ->and(json_encode($payload))->not->toContain('analyze')
        ->and(json_encode($payload))->not->toContain('improve')
        ->and(json_encode($payload))->not->toContain('translate')
        ->and(json_encode($payload))->not->toContain('governance')
        ->and(json_encode($payload))->not->toContain('republish');
});

it('does not expose unauthorized draft resources in the consumer metadata maps', function (): void {
    $context = makeDraftsInteractionMetadataContext();
    $other = makeDraftsInteractionMetadataContext(
        organizationName: 'Other Metadata Org',
        userEmail: 'other-metadata@example.com',
        draftCount: 1,
        draftTitlePrefix: 'Unauthorized draft'
    );

    $response = $this->actingAs($context['user'])
        ->get(draftsInteractionMetadataIndexUrl());

    $response->assertOk()
        ->assertSee('Metadata draft 1')
        ->assertDontSee('Unauthorized draft 1');

    $data = $response->original->getData();
    expect($data['interactionResourcesByKey'])->toHaveKey('draft:'.$context['drafts'][0]->id)
        ->and($data['interactionResourcesByKey'])->not->toHaveKey('draft:'.$other['drafts'][0]->id)
        ->and($data['interactionActionsByKey'])->not->toHaveKey('draft:'.$other['drafts'][0]->id);
});

it('keeps empty state links literal and leaves metadata maps empty when there are no draft rows', function (): void {
    $context = makeDraftsInteractionMetadataContext(draftCount: 0);

    $response = $this->actingAs($context['user'])
        ->get(draftsInteractionMetadataIndexUrl(['site' => (string) $context['site']->id]));

    $response->assertOk()
        ->assertSee('No drafts yet')
        ->assertSee('href="'.route('app.briefs.create').'"', false)
        ->assertSee('href="'.route('app.content.index').'"', false);

    $data = $response->original->getData();
    expect($data['drafts']->total())->toBe(0)
        ->and($data['interactionResourcesByKey'])->toBe([])
        ->and($data['interactionActionsByKey'])->toBe([]);
});

it('keeps pagination and site filters authoritative while resolving metadata for the current page only', function (): void {
    $context = makeDraftsInteractionMetadataContext(draftCount: 21);
    $otherSite = makeDraftsInteractionMetadataSite($context['workspace'], 'Filtered-out Site');
    $otherDraft = makeDraftsInteractionMetadataDraft($otherSite, title: 'Filtered-out draft');

    $response = $this->actingAs($context['user'])
        ->get(draftsInteractionMetadataIndexUrl(['site' => (string) $context['site']->id]));

    $response->assertOk()
        ->assertSee('Metadata draft')
        ->assertDontSee('href="'.route('app.drafts.show', $otherDraft).'"', false)
        ->assertSee('page=2', false)
        ->assertSee('site='.$context['site']->id, false);

    $data = $response->original->getData();
    expect($data['drafts']->total())->toBe(21)
        ->and($data['drafts']->count())->toBe(20)
        ->and($data['drafts']->pluck('id')->map(fn ($id): string => (string) $id)->all())->not->toContain((string) $otherDraft->id)
        ->and($data['interactionResourcesByKey'])->toHaveCount(20)
        ->and($data['interactionResourcesByKey'])->not->toHaveKey('draft:'.$otherDraft->id);
});

it('resolves draft metadata without lazy-loading row relations', function (): void {
    $context = makeDraftsInteractionMetadataContext(draftCount: 3);
    $context['user']->load('organization');

    Model::preventLazyLoading();

    try {
        $view = $this->actingAs($context['user'])
            ->get(draftsInteractionMetadataIndexUrl())
            ->assertOk()
            ->original;

        expect($view->getData()['interactionResourcesByKey'])->toHaveCount(3);
    } finally {
        Model::preventLazyLoading(false);
    }
});

function draftsInteractionMetadataIndexUrl(array $query = []): string
{
    if (! Route::has('interaction-metadata.drafts.index')) {
        Route::get('/__interaction-metadata/drafts', [AppDraftsController::class, 'index'])
            ->middleware('web')
            ->name('interaction-metadata.drafts.index');
    }

    $url = '/__interaction-metadata/drafts';

    if ($query !== []) {
        $url .= '?'.http_build_query($query);
    }

    return $url;
}

function draftsInteractionHtmlAttribute(string $html, string $attribute): ?string
{
    preg_match('/\s'.preg_quote($attribute, '/').'="([^"]*)"/', $html, $matches);

    return isset($matches[1]) ? html_entity_decode($matches[1]) : null;
}

function makeDraftsInteractionMetadataContext(
    string $organizationName = 'Draft Metadata Org',
    string $userEmail = 'draft-metadata@example.com',
    int $draftCount = 1,
    string $draftTitlePrefix = 'Metadata draft',
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

    $site = makeDraftsInteractionMetadataSite($workspace, $organizationName.' Site');

    $user = User::query()->create([
        'name' => $organizationName.' User',
        'email' => $userEmail,
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $drafts = $draftCount <= 0
        ? []
        : collect(range(1, $draftCount))
            ->map(fn (int $number): Draft => makeDraftsInteractionMetadataDraft(
                $site,
                title: $draftTitlePrefix.' '.$number,
                createdAt: now()->subMinutes($number)
            ))
            ->all();

    return compact('organization', 'workspace', 'site', 'user', 'drafts');
}

function makeDraftsInteractionMetadataSite(Workspace $workspace, string $name): ClientSite
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

function makeDraftsInteractionMetadataDraft(
    ClientSite $site,
    string $title = 'Metadata draft',
    mixed $createdAt = null,
): Draft {
    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $site->workspace_id,
        'client_site_id' => $site->id,
        'title' => $title.' content',
        'primary_keyword' => 'metadata draft',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'external_key' => (string) Str::uuid(),
        'generation_mode' => 'balanced',
        'preferred_length' => 'medium',
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'ready',
        'title' => $title.' brief',
        'language' => 'en',
        'primary_keyword' => 'metadata draft',
        'source' => 'client_ui',
    ]);

    return Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'ready',
        'title' => $title,
        'output_type' => 'kb_article',
        'language' => 'en',
        'draft_type' => 'original',
        'delivery_status' => 'pending',
        'content_html' => '<p>Metadata draft body.</p>',
        'created_at' => $createdAt ?? now(),
        'updated_at' => $createdAt ?? now(),
    ]);
}
