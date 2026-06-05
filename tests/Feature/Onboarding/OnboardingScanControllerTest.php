<?php

use App\Jobs\Onboarding\GenerateInitialContentJob;
use App\Jobs\Onboarding\ScanWebsiteJob;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\User;
use App\Models\WebsiteScan;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
});

describe('OnboardingScanController', function () {

    it('starts a new website scan for authenticated user', function () {
        $user = createOnboardingScanTestUser();

        $response = $this->actingAs($user)
            ->postJson(route('app.onboarding.scan.store'), [
                'url' => 'https://example.com',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'scan_id',
                'status',
                'message',
            ]);

        expect(WebsiteScan::where('organization_id', $user->organization_id)->exists())->toBeTrue();

        Queue::assertPushed(ScanWebsiteJob::class);
    });

    it('rejects scan request with invalid URL', function () {
        $user = createOnboardingScanTestUser();

        $response = $this->actingAs($user)
            ->postJson(route('app.onboarding.scan.store'), [
                'url' => 'not-a-valid-url',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['url']);
    });

    it('prevents duplicate scans when one is in progress', function () {
        $user = createOnboardingScanTestUser();

        // Create an existing in-progress scan
        $existingScan = WebsiteScan::create([
            'id' => (string) Str::uuid(),
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'url' => 'https://example.com',
            'status' => WebsiteScan::STATUS_CRAWLING,
            'progress' => 0.3,
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('app.onboarding.scan.store'), [
                'url' => 'https://another-site.com',
            ]);

        $response->assertStatus(409)
            ->assertJson([
                'error' => 'A scan is already in progress.',
                'scan_id' => $existingScan->id,
            ]);
    });

    it('returns scan status and progress', function () {
        $user = createOnboardingScanTestUser();

        $scan = WebsiteScan::create([
            'id' => (string) Str::uuid(),
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'url' => 'https://example.com',
            'status' => WebsiteScan::STATUS_ANALYZING,
            'progress' => 0.7,
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('app.onboarding.scan.show', $scan->id));

        $response->assertOk()
            ->assertJson([
                'id' => $scan->id,
                'url' => 'https://example.com',
                'status' => 'analyzing',
                'progress' => 0.7,
            ]);
    });

    it('returns completed scan with profiles', function () {
        $user = createOnboardingScanTestUser();

        $scan = WebsiteScan::create([
            'id' => (string) Str::uuid(),
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'url' => 'https://example.com',
            'status' => WebsiteScan::STATUS_COMPLETED,
            'progress' => 1.0,
            'brand_profile' => ['company_name' => 'Test Company'],
            'seo_profile' => ['primary_keywords' => ['test', 'keyword']],
            'design_profile' => ['primary_colors' => ['#ff0000']],
            'technical_profile' => ['detected_cms' => ['wordpress']],
            'suggested_briefs' => [
                ['title' => 'Test Brief', 'primary_keyword' => 'test'],
            ],
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('app.onboarding.scan.show', $scan->id));

        $response->assertOk()
            ->assertJsonStructure([
                'id',
                'brand_profile',
                'seo_profile',
                'design_profile',
                'technical_profile',
                'suggested_briefs',
            ])
            ->assertJsonPath('brand_profile.company_name', 'Test Company');
    });

    it('returns 404 for scan belonging to different organization', function () {
        $user = createOnboardingScanTestUser();
        $otherOrg = Organization::query()->create([
            'name' => 'Other Org',
            'slug' => 'other-org-' . Str::lower(Str::random(8)),
            'status' => 'active',
        ]);

        $scan = WebsiteScan::create([
            'id' => (string) Str::uuid(),
            'organization_id' => $otherOrg->id,
            'user_id' => $user->id,
            'url' => 'https://example.com',
            'status' => WebsiteScan::STATUS_QUEUED,
            'progress' => 0,
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('app.onboarding.scan.show', $scan->id));

        $response->assertStatus(404);
    });

    it('confirms completed scan and applies profiles to organization', function () {
        $user = createOnboardingScanTestUser();

        $scan = WebsiteScan::create([
            'id' => (string) Str::uuid(),
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'url' => 'https://example.com',
            'status' => WebsiteScan::STATUS_COMPLETED,
            'progress' => 1.0,
            'brand_profile' => ['company_name' => 'Scanned Company'],
            'seo_profile' => ['primary_keywords' => ['seo']],
            'design_profile' => ['primary_colors' => ['#00ff00']],
            'technical_profile' => ['detected_cms' => ['laravel']],
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('app.onboarding.scan.confirm', $scan->id), [
                'apply_to_organization' => true,
            ]);

        $response->assertOk()
            ->assertJson([
                'profiles_applied' => true,
            ]);

        $user->organization->refresh();
        expect($user->organization->brand_profile)->toBe(['company_name' => 'Scanned Company']);
        expect($user->organization->onboarding_scan_id)->toBe($scan->id);

        $scan->refresh();
        expect($scan->user_confirmed)->toBeTrue();
    });

    it('confirms scan and queues content generation when requested', function () {
        $user = createOnboardingScanTestUser();
        $clientSite = createOnboardingScanTestClientSite($user);

        $scan = WebsiteScan::create([
            'id' => (string) Str::uuid(),
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'url' => 'https://example.com',
            'status' => WebsiteScan::STATUS_COMPLETED,
            'progress' => 1.0,
            'suggested_briefs' => [
                ['title' => 'Test Brief', 'primary_keyword' => 'test'],
            ],
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('app.onboarding.scan.confirm', $scan->id), [
                'client_site_id' => $clientSite->id,
                'generate_content' => true,
            ]);

        $response->assertOk()
            ->assertJson([
                'content_generation_queued' => true,
            ]);

        Queue::assertPushed(GenerateInitialContentJob::class);
    });

    it('rejects confirmation of incomplete scan', function () {
        $user = createOnboardingScanTestUser();

        $scan = WebsiteScan::create([
            'id' => (string) Str::uuid(),
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'url' => 'https://example.com',
            'status' => WebsiteScan::STATUS_CRAWLING,
            'progress' => 0.3,
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('app.onboarding.scan.confirm', $scan->id));

        $response->assertStatus(422)
            ->assertJson([
                'error' => 'Scan is not completed yet.',
            ]);
    });

    it('prevents duplicate confirmation', function () {
        $user = createOnboardingScanTestUser();

        $scan = WebsiteScan::create([
            'id' => (string) Str::uuid(),
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'url' => 'https://example.com',
            'status' => WebsiteScan::STATUS_COMPLETED,
            'progress' => 1.0,
            'user_confirmed' => true,
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('app.onboarding.scan.confirm', $scan->id));

        $response->assertStatus(409)
            ->assertJson([
                'error' => 'Scan has already been confirmed.',
            ]);
    });

    it('returns latest scan for organization', function () {
        $user = createOnboardingScanTestUser();

        // Create older scan first with explicit old timestamp
        WebsiteScan::query()->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'url' => 'https://old-site.com',
            'status' => WebsiteScan::STATUS_COMPLETED,
            'progress' => 1.0,
            'created_at' => '2020-01-01 00:00:00',
            'updated_at' => '2020-01-01 00:00:00',
        ]);

        // Create newer scan
        $newerScan = WebsiteScan::create([
            'id' => (string) Str::uuid(),
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'url' => 'https://new-site.com',
            'status' => WebsiteScan::STATUS_COMPLETED,
            'progress' => 1.0,
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('app.onboarding.scan.latest'));

        $response->assertOk()
            ->assertJsonPath('url', 'https://new-site.com');
    });
});

function createOnboardingScanTestUser(): User
{
    $organization = Organization::query()->create([
        'name' => 'Test Org ' . Str::random(4),
        'slug' => 'test-org-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    return User::query()->create([
        'name' => 'Test User',
        'email' => 'test+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'email_verified_at' => now(),
    ]);
}

function createOnboardingScanTestClientSite(User $user): ClientSite
{
    $workspace = Workspace::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $user->organization_id,
        'name' => 'Test Workspace',
        'slug' => 'test-workspace-' . Str::lower(Str::random(8)),
    ]);

    return ClientSite::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'name' => 'Test Site',
        'site_url' => 'https://example.com',
        'allowed_domains' => 'example.com',
    ]);
}
