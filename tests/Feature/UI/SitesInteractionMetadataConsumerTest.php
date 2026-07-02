<?php

use App\Http\Controllers\App\AppSitesController;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Interaction\Providers\AppSiteInteractionProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('keeps site row setup links and connector POST forms authoritative while resolving row metadata', function (): void {
    $context = makeSitesInteractionMetadataContext();
    $wordpressSite = $context['sites'][0];
    $laravelSite = makeSitesInteractionMetadataSite(
        $context['workspace'],
        'Metadata Laravel Site',
        ClientSite::TYPE_LARAVEL,
        'https://metadata-laravel.example.com',
    );

    $response = $this->actingAs($context['user'])
        ->get(sitesInteractionMetadataIndexUrl());

    $response->assertOk()
        ->assertSee('href="'.route('app.sites.show', $wordpressSite).'"', false)
        ->assertSee('href="'.route('app.sites.show', $laravelSite).'"', false)
        ->assertSee('View setup details')
        ->assertSee('<form method="POST" action="'.route('app.sites.test-wordpress', $wordpressSite).'">', false)
        ->assertSee('<form method="POST" action="'.route('app.sites.test-laravel', $laravelSite).'">', false)
        ->assertSee('Test connection');

    $data = $response->original->getData();
    expect($data['interactionResourcesByKey'])->toHaveKey('site:'.$wordpressSite->id)
        ->and($data['interactionResourcesByKey']['site:'.$wordpressSite->id]['key'])->toBe('site:'.$wordpressSite->id)
        ->and($data['interactionActionsByKey']['site:'.$wordpressSite->id])->toHaveKey(AppSiteInteractionProvider::ACTION_SITE_OPEN)
        ->and($data['interactionActionsByKey']['site:'.$wordpressSite->id][AppSiteInteractionProvider::ACTION_SITE_OPEN]['method'])->toBe('GET')
        ->and($data['interactionActionsByKey']['site:'.$wordpressSite->id][AppSiteInteractionProvider::ACTION_SITE_OPEN]['route']['name'])->toBe('app.sites.show');
});

it('keeps the add-site POST form and generated key block literal', function (): void {
    $context = makeSitesInteractionMetadataContext();
    $site = $context['sites'][0];

    $response = $this->withSession([
        'site_plain_key' => 'arg_site_metadata_plain_key',
        'site_generated_for' => (string) $site->id,
    ])->actingAs($context['user'])
        ->get(sitesInteractionMetadataIndexUrl());

    $response->assertOk()
        ->assertSee('<form method="POST" action="'.route('app.sites.store').'"', false)
        ->assertSee('Add site and generate key')
        ->assertSee('New site key generated')
        ->assertSee('Copy this key now. It is shown only once.')
        ->assertSee('<div class="mt-3 rounded border border-border bg-surface px-3 py-2 font-mono text-sm text-textPrimary" id="site-key-value">arg_site_metadata_plain_key</div>', false)
        ->assertSee('Copy key')
        ->assertSee('WordPress setup');

    $data = $response->original->getData();
    expect($data['generatedKey'])->toBe('arg_site_metadata_plain_key')
        ->and($data['generatedSiteId'])->toBe((string) $site->id)
        ->and($data['interactionResourcesByKey'])->toHaveKey('site:'.$site->id);
});

it('does not expose unauthorized site resources in the consumer metadata maps', function (): void {
    $context = makeSitesInteractionMetadataContext();
    $other = makeSitesInteractionMetadataContext(
        organizationName: 'Other Site Metadata Org',
        userEmail: 'other-site-metadata@example.com',
        siteName: 'Unauthorized metadata site',
        siteUrl: 'https://unauthorized-metadata.example.com',
    );

    $response = $this->actingAs($context['user'])
        ->get(sitesInteractionMetadataIndexUrl());

    $response->assertOk()
        ->assertSee($context['sites'][0]->name)
        ->assertDontSee('Unauthorized metadata site')
        ->assertDontSee('href="'.route('app.sites.show', $other['sites'][0]).'"', false);

    $data = $response->original->getData();
    expect($data['interactionResourcesByKey'])->toHaveKey('site:'.$context['sites'][0]->id)
        ->and($data['interactionResourcesByKey'])->not->toHaveKey('site:'.$other['sites'][0]->id)
        ->and($data['interactionActionsByKey'])->not->toHaveKey('site:'.$other['sites'][0]->id);
});

it('resolves site metadata for the current paginator collection only', function (): void {
    $context = makeSitesInteractionMetadataContext();

    foreach (range(1, 17) as $number) {
        makeSitesInteractionMetadataSite(
            $context['workspace'],
            'Paged Metadata Site '.$number,
            ClientSite::TYPE_WORDPRESS,
            'https://paged-metadata-'.$number.'.example.com',
            now()->subMinutes($number),
        );
    }

    $response = $this->actingAs($context['user'])
        ->get(sitesInteractionMetadataIndexUrl());

    $response->assertOk()
        ->assertSee('page=2', false);

    $data = $response->original->getData();
    $visibleSiteKeys = $data['sites']->getCollection()
        ->map(fn (ClientSite $site): string => 'site:'.$site->id)
        ->all();

    expect($data['sites']->count())->toBe(15)
        ->and(array_keys($data['interactionResourcesByKey']))->toBe($visibleSiteKeys)
        ->and(array_keys($data['interactionActionsByKey']))->toBe($visibleSiteKeys);
});

it('resolves site metadata from eager-loaded workspace context without N+1 relation loading', function (): void {
    $context = makeSitesInteractionMetadataContext(siteCount: 3);
    $context['user']->load('organization');

    Model::preventLazyLoading();

    try {
        $view = $this->actingAs($context['user'])
            ->get(sitesInteractionMetadataIndexUrl())
            ->assertOk()
            ->original;

        $sites = $view->getData()['sites']->getCollection();

        expect($view->getData()['interactionResourcesByKey'])->toHaveCount(3);

        foreach ($sites as $site) {
            expect($site->relationLoaded('workspace'))->toBeTrue();
        }
    } finally {
        Model::preventLazyLoading(false);
    }
});

function sitesInteractionMetadataIndexUrl(array $query = []): string
{
    if (! Route::has('interaction-metadata.sites.index')) {
        Route::get('/__interaction-metadata/sites', [AppSitesController::class, 'index'])
            ->middleware('web')
            ->name('interaction-metadata.sites.index');
    }

    $url = '/__interaction-metadata/sites';

    if ($query !== []) {
        $url .= '?'.http_build_query($query);
    }

    return $url;
}

function makeSitesInteractionMetadataContext(
    string $organizationName = 'Site Metadata Org',
    string $userEmail = 'site-metadata@example.com',
    int $siteCount = 1,
    string $siteName = 'Metadata WordPress Site',
    string $siteUrl = 'https://metadata-wordpress.example.com',
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

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'site-metadata-test-plan'],
        [
            'name' => 'Site Metadata Test Plan',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 100,
        ]
    );

    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
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

    $sites = $siteCount <= 0
        ? []
        : collect(range(1, $siteCount))
            ->map(fn (int $number): ClientSite => makeSitesInteractionMetadataSite(
                $workspace,
                $siteCount === 1 ? $siteName : $siteName.' '.$number,
                ClientSite::TYPE_WORDPRESS,
                $siteCount === 1 ? $siteUrl : 'https://metadata-wordpress-'.$number.'.example.com',
                now()->subMinutes($number),
            ))
            ->all();

    return compact('organization', 'workspace', 'user', 'sites');
}

function makeSitesInteractionMetadataSite(
    Workspace $workspace,
    string $name,
    string $type,
    string $url,
    mixed $createdAt = null,
): ClientSite {
    return ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => $type,
        'name' => $name,
        'site_url' => $url,
        'base_url' => $url,
        'allowed_domains' => [parse_url($url, PHP_URL_HOST)],
        'is_active' => true,
        'status' => 'connected',
        'last_seen_at' => now()->subHour(),
        'created_at' => $createdAt ?? now(),
        'updated_at' => $createdAt ?? now(),
    ]);
}
