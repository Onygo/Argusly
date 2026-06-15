<?php

use App\Enums\OpportunityCategory;
use App\Enums\OpportunityStatus;
use App\Http\Middleware\EnsureBillingOnboardingCompleted;
use App\Models\AssistantFeedItem;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\ContentPackage;
use App\Models\Draft;
use App\Models\GrowthAutopilotQueueItem;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\SocialPostVariant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Assistant\AssistantFeedService;
use App\Services\ContentPackages\ContentPackageService;
use App\Services\GrowthAutopilot\GrowthAutopilotQueueBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);
});

function contentPackageContext(string $slug = 'main'): array
{
    $organization = Organization::query()->create([
        'name' => 'Content Package '.$slug,
        'slug' => 'content-package-'.$slug.'-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Content Package Workspace '.$slug,
        'display_name' => 'Content Package Workspace '.$slug,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Content Package Site '.$slug,
        'site_url' => 'https://'.$slug.'.content-package.test',
        'base_url' => 'https://'.$slug.'.content-package.test',
        'allowed_domains' => [$slug.'.content-package.test'],
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);

    return compact('organization', 'workspace', 'site', 'user');
}

function contentPackageOpportunity(Workspace $workspace, ClientSite $site, array $overrides = []): Opportunity
{
    return Opportunity::query()->create(array_merge([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'category' => OpportunityCategory::CONTENT_GAP->value,
        'status' => OpportunityStatus::OPEN->value,
        'title' => 'Prepare a content package for AI visibility buyers',
        'topic' => 'AI visibility',
        'summary' => 'High-intent buyers need a clearer path from AI visibility problem to action.',
        'priority_score' => 90,
        'confidence_score' => 82,
        'impact_score' => 88,
        'urgency_score' => 70,
        'effort_score' => 35,
        'score_breakdown' => [],
        'recommended_actions' => [
            ['title' => 'Prepare the brief, draft, LinkedIn variant, CTA, links, and checklist.'],
        ],
        'evidence' => [],
        'source_signal_summary' => [],
        'dedupe_hash' => hash('sha256', Str::uuid()->toString()),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ], $overrides));
}

function contentPackageQueueItem(Workspace $workspace, ClientSite $site): GrowthAutopilotQueueItem
{
    contentPackageOpportunity($workspace, $site);

    return app(GrowthAutopilotQueueBuilder::class)->build($workspace)->first();
}

it('prepares a complete content package with one click', function (): void {
    $context = contentPackageContext('prepare');
    $item = contentPackageQueueItem($context['workspace'], $context['site']);

    $response = $this->actingAs($context['user'])
        ->post(route('app.content-packages.from-queue', $item));

    $package = ContentPackage::query()->firstOrFail();

    $response->assertRedirect(route('app.drafts.show', $package->draft_id));

    expect($package)
        ->status->toBe(ContentPackage::STATUS_PREPARED)
        ->brief_id->not->toBeNull()
        ->draft_id->not->toBeNull()
        ->linkedin_variant_id->not->toBeNull();

    expect($package->cta_recommendation)->toHaveKey('text');
    expect($package->internal_linking_suggestions)->not->toBeEmpty();
    expect($package->publishing_checklist)->toHaveCount(6);
    expect(collect($package->prepared_assets)->pluck('type')->all())
        ->toContain('brief', 'draft', 'linkedin_variant', 'cta_recommendation', 'internal_linking_suggestions', 'publishing_checklist');

    $this->assertDatabaseHas('briefs', ['id' => $package->brief_id, 'source' => 'content_package']);
    $this->assertDatabaseHas('drafts', ['id' => $package->draft_id, 'brief_id' => $package->brief_id]);
    $this->assertDatabaseHas('social_post_variants', ['id' => $package->linkedin_variant_id, 'variant_type' => 'content_package']);
});

it('is idempotent for the same queue item', function (): void {
    $context = contentPackageContext('idempotent');
    $item = contentPackageQueueItem($context['workspace'], $context['site']);

    $first = app(ContentPackageService::class)->prepareFromQueueItem($item, $context['user']);
    $second = app(ContentPackageService::class)->prepareFromQueueItem($item->fresh(), $context['user']);

    expect($second->id)->toBe($first->id);
    expect(ContentPackage::query()->count())->toBe(1);
    expect(Brief::query()->count())->toBe(1);
    expect(Draft::query()->count())->toBe(1);
    expect(SocialPostVariant::query()->count())->toBe(1);
});

it('converts content packages into assistant feed messages', function (): void {
    $context = contentPackageContext('assistant');
    $item = contentPackageQueueItem($context['workspace'], $context['site']);
    $package = app(ContentPackageService::class)->prepareFromQueueItem($item, $context['user']);

    $assistantItem = app(AssistantFeedService::class)->upsertFromSource($package, false);

    expect($assistantItem)
        ->toBeInstanceOf(AssistantFeedItem::class)
        ->assistant_state->toBe(AssistantFeedItem::STATE_PREPARED)
        ->i_prepared->toContain('brief, draft, LinkedIn variant, CTA recommendation, internal linking suggestions, and publishing checklist')
        ->i_need_your_input->toContain('Review the draft');
});
