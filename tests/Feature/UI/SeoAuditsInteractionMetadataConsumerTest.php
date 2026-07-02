<?php

use App\Http\Controllers\App\AppSiteSeoAuditController;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\SeoAudit;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Interaction\Providers\AppSiteInteractionProvider;
use App\Support\Interaction\ResourceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('keeps SEO audit open links authoritative while resolving row metadata', function (): void {
    $context = makeSeoAuditsInteractionMetadataContext();
    $audit = $context['audits'][0];

    $response = $this->actingAs($context['user'])
        ->get(seoAuditsInteractionMetadataIndexUrl($context['site']));

    $response->assertOk()
        ->assertSee('<a href="'.route('app.sites.seo-audits.show', [$context['site'], $audit]).'" class="rounded border border-border px-2 py-1 text-xs">Open</a>', false)
        ->assertSee('Open');

    $data = $response->original->getData();
    expect($data['interactionResourcesByKey'])->toHaveKey('seo_audit:'.$audit->id)
        ->and($data['interactionResourcesByKey']['seo_audit:'.$audit->id]['key'])->toBe('seo_audit:'.$audit->id)
        ->and($data['interactionActionsByKey']['seo_audit:'.$audit->id])->toHaveKey(AppSiteInteractionProvider::ACTION_SEO_AUDIT_OPEN);
});

it('renders an additive SEO audit inspect drawer trigger with GET link metadata only', function (): void {
    $context = makeSeoAuditsInteractionMetadataContext();
    $audit = $context['audits'][0];
    $auditUrl = route('app.sites.seo-audits.show', [$context['site'], $audit]);

    $response = $this->actingAs($context['user'])
        ->get(seoAuditsInteractionMetadataIndexUrl($context['site']));

    $response->assertOk()
        ->assertSee('<a href="'.$auditUrl.'" class="rounded border border-border px-2 py-1 text-xs">Open</a>', false);

    $html = $response->getContent();
    preg_match('/<a\b(?=[^>]*data-drawer-trigger="button")(?=[^>]*data-drawer-target="seo-audit\.inspect")[^>]*>.*?<\/a>/s', $html, $matches);
    $drawerTrigger = $matches[0] ?? '';

    expect($drawerTrigger)->not->toBe('')
        ->and(seoAuditsInteractionHtmlAttribute($drawerTrigger, 'href'))->toBe($auditUrl)
        ->and(seoAuditsInteractionHtmlAttribute($drawerTrigger, 'role'))->toBe('button')
        ->and(seoAuditsInteractionHtmlAttribute($drawerTrigger, 'data-drawer-mode'))->toBe('inspect')
        ->and(seoAuditsInteractionHtmlAttribute($drawerTrigger, 'data-drawer-resource-type'))->toBe(ResourceType::SEO_AUDIT)
        ->and(seoAuditsInteractionHtmlAttribute($drawerTrigger, 'data-drawer-resource-key'))->toBe('seo_audit:'.$audit->id)
        ->and(seoAuditsInteractionHtmlAttribute($drawerTrigger, 'data-drawer-resource-id'))->toBe((string) $audit->id)
        ->and(seoAuditsInteractionHtmlAttribute($drawerTrigger, 'data-drawer-action-key'))->toBe(AppSiteInteractionProvider::ACTION_SEO_AUDIT_OPEN)
        ->and(seoAuditsInteractionHtmlAttribute($drawerTrigger, 'data-progressive-enhancement'))->toBe('true')
        ->and(seoAuditsInteractionHtmlAttribute($drawerTrigger, 'data-command-palette-ready'))->toBe('true')
        ->and(trim(strip_tags($drawerTrigger)))->toBe('Inspect');

    $payload = json_decode(html_entity_decode((string) seoAuditsInteractionHtmlAttribute($drawerTrigger, 'data-drawer-payload')), true);

    expect($payload)
        ->toBeArray()
        ->and($payload['href'])->toBe($auditUrl)
        ->and($payload['target'])->toMatchArray([
            'target' => 'seo-audit.inspect',
            'mode' => 'inspect',
            'resource_type' => ResourceType::SEO_AUDIT,
            'resource_key' => 'seo_audit:'.$audit->id,
            'resource_id' => $audit->id,
            'action_key' => AppSiteInteractionProvider::ACTION_SEO_AUDIT_OPEN,
        ])
        ->and($payload['resource'])->toMatchArray([
            'key' => 'seo_audit:'.$audit->id,
            'type' => ResourceType::SEO_AUDIT,
            'id' => $audit->id,
            'available_actions' => [AppSiteInteractionProvider::ACTION_SEO_AUDIT_OPEN],
        ])
        ->and($payload['action'])->toMatchArray([
            'key' => AppSiteInteractionProvider::ACTION_SEO_AUDIT_OPEN,
            'method' => 'GET',
            'execution_mode' => 'link',
            'resource' => [
                'type' => ResourceType::SEO_AUDIT,
                'id' => $audit->id,
            ],
        ])
        ->and($payload['tabs'])->toBe([])
        ->and($payload['sections'])->toBe([])
        ->and($payload['footer_actions'])->toBe([])
        ->and($payload['preview'])->toBe([])
        ->and($payload['ai'])->toBe([])
        ->and($payload['relationships'])->toBe([]);

    $redactedPayload = seoAuditsInteractionPayloadWithoutRequiredIdentifiers($payload);

    expect($redactedPayload)
        ->not->toContain('POST')
        ->not->toContain('post')
        ->not->toContain('run')
        ->not->toContain('audit')
        ->not->toContain('ai-fix')
        ->not->toContain('generate')
        ->not->toContain('apply')
        ->not->toContain('sync')
        ->not->toContain('heavy')
        ->not->toContain('destructive');
});

it('keeps run audit as the protected POST form and leaves header links literal', function (): void {
    $context = makeSeoAuditsInteractionMetadataContext();

    $response = $this->actingAs($context['user'])
        ->get(seoAuditsInteractionMetadataIndexUrl($context['site']));

    $response->assertOk()
        ->assertSee('href="'.route('app.insights.index').'"', false)
        ->assertSee('All sites')
        ->assertSee('href="'.route('app.sites.show', $context['site']).'"', false)
        ->assertSee('Site setup')
        ->assertSee('method="POST"', false)
        ->assertSee('action="'.route('app.sites.seo-audits.run', $context['site']).'"', false)
        ->assertSee('Run SEO audit');

    $resolvedActionKeys = collect($response->original->getData()['interactionActionsByKey'])
        ->flatMap(fn (array $actions): array => array_keys($actions))
        ->all();

    expect($resolvedActionKeys)->toContain(AppSiteInteractionProvider::ACTION_SEO_AUDIT_OPEN)
        ->and($resolvedActionKeys)->not->toContain('app.sites.seo-audits.run');
});

it('does not expose unauthorized SEO audit resources in the consumer metadata maps', function (): void {
    $context = makeSeoAuditsInteractionMetadataContext();
    $other = makeSeoAuditsInteractionMetadataContext(
        organizationName: 'Other SEO Audit Metadata Org',
        userEmail: 'other-seo-audit-metadata@example.com',
        auditCount: 1
    );

    $response = $this->actingAs($context['user'])
        ->get(seoAuditsInteractionMetadataIndexUrl($context['site']));

    $response->assertOk()
        ->assertDontSee('href="'.route('app.sites.seo-audits.show', [$other['site'], $other['audits'][0]]).'"', false);

    $data = $response->original->getData();
    expect($data['interactionResourcesByKey'])->toHaveKey('seo_audit:'.$context['audits'][0]->id)
        ->and($data['interactionResourcesByKey'])->not->toHaveKey('seo_audit:'.$other['audits'][0]->id)
        ->and($data['interactionActionsByKey'])->not->toHaveKey('seo_audit:'.$other['audits'][0]->id);
});

it('keeps empty state literal when there are no SEO audit rows', function (): void {
    $context = makeSeoAuditsInteractionMetadataContext(auditCount: 0);

    $response = $this->actingAs($context['user'])
        ->get(seoAuditsInteractionMetadataIndexUrl($context['site']));

    $response->assertOk()
        ->assertSee('No audit runs yet')
        ->assertSee('method="POST"', false)
        ->assertSee('action="'.route('app.sites.seo-audits.run', $context['site']).'"', false)
        ->assertDontSee('data-drawer-trigger=', false);

    $data = $response->original->getData();
    expect($data['audits'])->toHaveCount(0)
        ->and($data['interactionResourcesByKey'])->toBe([])
        ->and($data['interactionActionsByKey'])->toBe([]);
});

it('resolves SEO audit metadata from eager-loaded site context without N+1 relation loading', function (): void {
    $context = makeSeoAuditsInteractionMetadataContext(auditCount: 6);
    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        $queries[] = Str::lower($query->sql);
    });

    $view = $this->actingAs($context['user'])
        ->get(seoAuditsInteractionMetadataIndexUrl($context['site']))
        ->assertOk()
        ->original;

    $audits = $view->getData()['audits'];

    expect($view->getData()['interactionResourcesByKey'])->toHaveCount(6)
        ->and($audits)->toHaveCount(6);

    foreach ($audits as $audit) {
        expect($audit->relationLoaded('site'))->toBeTrue()
            ->and($audit->site?->relationLoaded('workspace'))->toBeTrue();
    }

    $routeModelSiteLookups = collect($queries)
        ->filter(fn (string $sql): bool => (Str::contains($sql, 'from "client_sites"') || Str::contains($sql, 'from `client_sites`'))
            && Str::contains($sql, 'limit 1'))
        ->count();

    expect($routeModelSiteLookups)->toBeLessThanOrEqual(2);
});

function seoAuditsInteractionMetadataIndexUrl(ClientSite $site): string
{
    if (! Route::has('interaction-metadata.seo-audits.index')) {
        Route::get('/__interaction-metadata/sites/{site}/seo-audits', [AppSiteSeoAuditController::class, 'index'])
            ->middleware('web')
            ->name('interaction-metadata.seo-audits.index');
    }

    return '/__interaction-metadata/sites/'.$site->getRouteKey().'/seo-audits';
}

function seoAuditsInteractionHtmlAttribute(string $html, string $attribute): ?string
{
    preg_match('/\s'.preg_quote($attribute, '/').'="([^"]*)"/', $html, $matches);

    return isset($matches[1]) ? html_entity_decode($matches[1]) : null;
}

function seoAuditsInteractionPayloadWithoutRequiredIdentifiers(array $payload): string
{
    $redacted = $payload;

    foreach ([
        ['target', 'target'],
        ['target', 'resource_type'],
        ['target', 'resource_key'],
        ['target', 'action_key'],
        ['target', 'href'],
        ['href'],
        ['drawer_url'],
        ['history', 'fallback_url'],
        ['history', 'drawer_url'],
        ['history', 'parameters', 'drawer'],
        ['history', 'parameters', 'drawer_resource'],
        ['history', 'parameters', 'drawer_action'],
        ['resource', 'key'],
        ['resource', 'type'],
        ['resource', 'available_actions'],
        ['action', 'key'],
        ['action', 'url'],
        ['action', 'route'],
        ['action', 'resource', 'type'],
        ['data_attributes', 'data-drawer-target'],
        ['data_attributes', 'data-drawer-url'],
        ['data_attributes', 'data-drawer-resource-type'],
        ['data_attributes', 'data-drawer-resource-key'],
        ['data_attributes', 'data-drawer-action-key'],
    ] as $path) {
        data_set($redacted, implode('.', $path), '__required_identifier__');
    }

    return Str::lower((string) json_encode($redacted));
}

function makeSeoAuditsInteractionMetadataContext(
    string $organizationName = 'SEO Audit Metadata Org',
    string $userEmail = 'seo-audit-metadata@example.com',
    int $auditCount = 1,
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

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => $organizationName.' Site',
        'site_url' => 'https://'.Str::slug($organizationName).'.example.com',
        'base_url' => 'https://'.Str::slug($organizationName).'.example.com',
        'allowed_domains' => [Str::slug($organizationName).'.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $user = User::query()->create([
        'name' => $organizationName.' User',
        'email' => $userEmail,
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $audits = $auditCount <= 0
        ? []
        : collect(range(1, $auditCount))
            ->map(fn (int $number): SeoAudit => SeoAudit::query()->create([
                'workspace_id' => $workspace->id,
                'client_site_id' => $site->id,
                'started_at' => now()->subMinutes($number),
                'finished_at' => now()->subMinutes($number - 1),
                'status' => 'completed',
                'pages_crawled' => 10 + $number,
                'issue_counts' => [
                    'error' => $number,
                    'warning' => $number + 1,
                    'info' => $number + 2,
                ],
                'meta' => [],
                'created_at' => now()->subMinutes($number),
                'updated_at' => now()->subMinutes($number),
            ]))
            ->all();

    return compact('organization', 'workspace', 'site', 'user', 'audits');
}
