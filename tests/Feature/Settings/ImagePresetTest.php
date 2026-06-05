<?php

use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ImagePreset;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ImagePresetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->organization = Organization::create([
        'name' => 'Test Org',
        'slug' => 'test-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Test Org BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $this->workspace = Workspace::create([
        'name' => 'Test Workspace',
        'organization_id' => $this->organization->id,
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'test-plan'],
        [
            'name' => 'Test Plan',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 100,
        ]
    );

    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $this->organization->id,
        'workspace_id' => $this->workspace->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $this->adminUser = User::create([
        'name' => 'Admin User',
        'email' => 'admin+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $this->organization->id,
        'role' => 'admin',
        'approved_at' => now(),
        'active' => true,
    ]);

    $this->editorUser = User::create([
        'name' => 'Editor User',
        'email' => 'editor+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $this->organization->id,
        'role' => 'editor',
        'approved_at' => now(),
        'active' => true,
    ]);

    $this->presetService = app(ImagePresetService::class);
});

describe('ImagePresetService', function () {
    it('creates first preset as default automatically', function () {
        $preset = $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'First Preset', 'instructions' => 'Clean aesthetic'],
            $this->adminUser->id
        );

        expect($preset->is_default)->toBeTrue();
        expect($preset->name)->toBe('First Preset');
        expect($preset->organization_id)->toBe($this->organization->id);
    });

    it('second preset is not default by default', function () {
        $first = $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'First', 'instructions' => 'Clean'],
            $this->adminUser->id
        );

        $second = $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'Second', 'instructions' => 'Vibrant'],
            $this->adminUser->id
        );

        expect($first->fresh()->is_default)->toBeTrue();
        expect($second->is_default)->toBeFalse();
    });

    it('can explicitly set new preset as default', function () {
        $first = $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'First', 'instructions' => 'Clean'],
            $this->adminUser->id
        );

        $second = $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'Second', 'instructions' => 'Vibrant', 'is_default' => true],
            $this->adminUser->id
        );

        expect($first->fresh()->is_default)->toBeFalse();
        expect($second->is_default)->toBeTrue();
    });

    it('setDefault switches default to specified preset', function () {
        $first = $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'First', 'instructions' => 'Clean'],
        );

        $second = $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'Second', 'instructions' => 'Vibrant'],
        );

        $this->presetService->setDefault($second);

        expect($first->fresh()->is_default)->toBeFalse();
        expect($second->fresh()->is_default)->toBeTrue();
    });

    it('deletePreset reassigns default to another preset', function () {
        $first = $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'First', 'instructions' => 'Clean'],
        );

        $second = $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'Second', 'instructions' => 'Vibrant'],
        );

        expect($first->is_default)->toBeTrue();

        $this->presetService->deletePreset($first);

        expect(ImagePreset::find($first->id))->toBeNull();
        expect($second->fresh()->is_default)->toBeTrue();
    });

    it('resolvePresetForGeneration returns specific preset when valid', function () {
        $first = $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'Default', 'instructions' => 'Clean'],
        );

        $second = $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'Custom', 'instructions' => 'Vibrant'],
        );

        $resolved = $this->presetService->resolvePresetForGeneration(
            $this->organization->id,
            $second->id
        );

        expect($resolved->id)->toBe($second->id);
    });

    it('resolvePresetForGeneration falls back to default when preset not found', function () {
        $default = $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'Default', 'instructions' => 'Clean'],
        );

        $resolved = $this->presetService->resolvePresetForGeneration(
            $this->organization->id,
            'non-existent-uuid'
        );

        expect($resolved->id)->toBe($default->id);
    });

    it('resolvePresetForGeneration returns null when no presets exist', function () {
        $resolved = $this->presetService->resolvePresetForGeneration($this->organization->id);

        expect($resolved)->toBeNull();
    });

    it('buildStyleInstructions returns preset instructions when preset exists', function () {
        $preset = $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'Custom', 'instructions' => 'Bold colors, dramatic lighting'],
        );

        $instructions = $this->presetService->buildStyleInstructions($preset);

        expect($instructions)->toBe('Bold colors, dramatic lighting');
    });

    it('buildStyleInstructions returns system defaults when no preset', function () {
        $instructions = $this->presetService->buildStyleInstructions(null);

        expect($instructions)->not->toBeEmpty();
        expect($instructions)->toContain('Clean');
    });

    it('presets are scoped to organization', function () {
        $otherOrg = Organization::create([
            'name' => 'Other Org',
            'slug' => 'other-org-' . Str::lower(Str::random(6)),
            'status' => 'active',
            'approved_at' => now(),
        ]);

        $myPreset = $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'My Preset', 'instructions' => 'My style'],
        );

        $otherPreset = $this->presetService->createPreset(
            $otherOrg->id,
            ['name' => 'Other Preset', 'instructions' => 'Other style'],
        );

        $myOrgPresets = ImagePreset::getAllForOrganization($this->organization->id);
        $otherOrgPresets = ImagePreset::getAllForOrganization($otherOrg->id);

        expect($myOrgPresets)->toHaveCount(1);
        expect($myOrgPresets->first()->id)->toBe($myPreset->id);

        expect($otherOrgPresets)->toHaveCount(1);
        expect($otherOrgPresets->first()->id)->toBe($otherPreset->id);
    });
});

describe('ImagePreset HTTP Routes', function () {
    it('admin can view presets index', function () {
        $this->actingAs($this->adminUser)
            ->get(route('app.settings.image-presets.index'))
            ->assertOk()
            ->assertSee('Image Presets');
    });

    it('admin can view create form', function () {
        $this->actingAs($this->adminUser)
            ->get(route('app.settings.image-presets.create'))
            ->assertOk()
            ->assertSee('Create Image Preset');
    });

    it('admin can create preset via form', function () {
        $this->actingAs($this->adminUser)
            ->post(route('app.settings.image-presets.store'), [
                'name' => 'New Preset',
                'instructions' => 'Clean and modern aesthetic',
            ])
            ->assertRedirect(route('app.settings.image-presets.index'));

        $this->assertDatabaseHas('image_presets', [
            'organization_id' => $this->organization->id,
            'name' => 'New Preset',
        ]);
    });

    it('admin can edit existing preset', function () {
        $preset = $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'Original', 'instructions' => 'Original instructions'],
            $this->adminUser->id
        );

        $this->actingAs($this->adminUser)
            ->get(route('app.settings.image-presets.edit', $preset))
            ->assertOk()
            ->assertSee('Original');
    });

    it('admin can update preset via form', function () {
        $preset = $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'Original', 'instructions' => 'Original instructions'],
            $this->adminUser->id
        );

        $this->actingAs($this->adminUser)
            ->put(route('app.settings.image-presets.update', $preset), [
                'name' => 'Updated',
                'instructions' => 'Updated instructions',
            ])
            ->assertRedirect(route('app.settings.image-presets.index'));

        expect($preset->fresh()->name)->toBe('Updated');
    });

    it('admin can delete preset', function () {
        $preset = $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'To Delete', 'instructions' => 'Will be deleted'],
            $this->adminUser->id
        );

        $presetId = $preset->id;

        $this->actingAs($this->adminUser)
            ->delete(route('app.settings.image-presets.destroy', $preset))
            ->assertRedirect(route('app.settings.image-presets.index'));

        $this->assertDatabaseMissing('image_presets', ['id' => $presetId]);
    });

    it('admin can set preset as default', function () {
        $first = $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'First', 'instructions' => 'First'],
            $this->adminUser->id
        );

        $second = $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'Second', 'instructions' => 'Second'],
            $this->adminUser->id
        );

        $this->actingAs($this->adminUser)
            ->post(route('app.settings.image-presets.set-default', $second))
            ->assertRedirect(route('app.settings.image-presets.index'));

        expect($first->fresh()->is_default)->toBeFalse();
        expect($second->fresh()->is_default)->toBeTrue();
    });

    it('prevents access to presets from other organizations', function () {
        $otherOrg = Organization::create([
            'name' => 'Other Org',
            'slug' => 'other-org-' . Str::lower(Str::random(6)),
            'status' => 'active',
            'approved_at' => now(),
        ]);

        $otherPreset = $this->presetService->createPreset(
            $otherOrg->id,
            ['name' => 'Other', 'instructions' => 'Other style'],
        );

        $this->actingAs($this->adminUser)
            ->get(route('app.settings.image-presets.edit', $otherPreset))
            ->assertNotFound();

        $this->actingAs($this->adminUser)
            ->delete(route('app.settings.image-presets.destroy', $otherPreset))
            ->assertNotFound();
    });
});

describe('ImagePresetService getPresetOptions', function () {
    it('returns presets formatted for dropdown', function () {
        $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'Default Preset', 'instructions' => 'Default instructions'],
            $this->adminUser->id
        );

        $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'Custom Preset', 'instructions' => 'Custom instructions'],
            $this->adminUser->id
        );

        $options = $this->presetService->getPresetOptions($this->organization->id);

        expect($options)->toHaveCount(2);
        expect($options[0])->toHaveKeys(['id', 'name', 'instructions', 'is_default']);
        expect($options[0]['is_default'])->toBeTrue();
        expect($options[0]['name'])->toBe('Default Preset');
    });

    it('returns empty array when no presets exist', function () {
        $options = $this->presetService->getPresetOptions($this->organization->id);

        expect($options)->toBeEmpty();
    });

    it('returns only presets for specified organization', function () {
        $otherOrg = Organization::create([
            'name' => 'Other Org',
            'slug' => 'other-org-' . Str::lower(Str::random(6)),
            'status' => 'active',
            'approved_at' => now(),
        ]);

        $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'My Preset', 'instructions' => 'Mine'],
        );

        $this->presetService->createPreset(
            $otherOrg->id,
            ['name' => 'Other Preset', 'instructions' => 'Other'],
        );

        $myOptions = $this->presetService->getPresetOptions($this->organization->id);
        $otherOptions = $this->presetService->getPresetOptions($otherOrg->id);

        expect($myOptions)->toHaveCount(1);
        expect($myOptions[0]['name'])->toBe('My Preset');

        expect($otherOptions)->toHaveCount(1);
        expect($otherOptions[0]['name'])->toBe('Other Preset');
    });

    it('getOrganizationPresets returns collection of presets', function () {
        $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'Preset A', 'instructions' => 'A'],
        );
        $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'Preset B', 'instructions' => 'B'],
        );

        $presets = $this->presetService->getOrganizationPresets($this->organization->id);

        expect($presets)->toHaveCount(2);
        expect($presets->first())->toBeInstanceOf(ImagePreset::class);
    });

    it('getDefaultPreset returns the default preset', function () {
        $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'Default', 'instructions' => 'Default'],
        );
        $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'Other', 'instructions' => 'Other'],
        );

        $default = $this->presetService->getDefaultPreset($this->organization->id);

        expect($default)->not->toBeNull();
        expect($default->name)->toBe('Default');
        expect($default->is_default)->toBeTrue();
    });

    it('getDefaultPreset returns null when no presets exist', function () {
        $default = $this->presetService->getDefaultPreset($this->organization->id);

        expect($default)->toBeNull();
    });
});

describe('ImagePreset JSON API', function () {
    it('returns presets as JSON', function () {
        $preset = $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'API Test', 'instructions' => 'Test instructions'],
            $this->adminUser->id
        );

        $response = $this->actingAs($this->adminUser)
            ->getJson(route('app.api.image-presets.index'))
            ->assertOk();

        $data = $response->json('data');
        expect($data)->toHaveCount(1);
        expect($data[0]['name'])->toBe('API Test');
        expect($data[0]['is_default'])->toBeTrue();
    });

    it('creates preset via JSON API', function () {
        $response = $this->actingAs($this->adminUser)
            ->postJson(route('app.api.image-presets.store'), [
                'name' => 'JSON Created',
                'instructions' => 'Created via API',
            ])
            ->assertStatus(201);

        expect($response->json('data.name'))->toBe('JSON Created');
        $this->assertDatabaseHas('image_presets', ['name' => 'JSON Created']);
    });

    it('validates required fields on JSON API create', function () {
        $this->actingAs($this->adminUser)
            ->postJson(route('app.api.image-presets.store'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'instructions']);
    });

    it('updates preset via JSON API', function () {
        $preset = $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'Original', 'instructions' => 'Original'],
            $this->adminUser->id
        );

        $this->actingAs($this->adminUser)
            ->putJson(route('app.api.image-presets.update', $preset), [
                'name' => 'Updated via API',
            ])
            ->assertOk();

        expect($preset->fresh()->name)->toBe('Updated via API');
    });

    it('deletes preset via JSON API', function () {
        $preset = $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'To Delete', 'instructions' => 'Will be deleted'],
            $this->adminUser->id
        );

        $presetId = $preset->id;

        $this->actingAs($this->adminUser)
            ->deleteJson(route('app.api.image-presets.destroy', $preset))
            ->assertOk();

        $this->assertDatabaseMissing('image_presets', ['id' => $presetId]);
    });

    it('sets default via JSON API', function () {
        $first = $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'First', 'instructions' => 'First'],
            $this->adminUser->id
        );

        $second = $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'Second', 'instructions' => 'Second'],
            $this->adminUser->id
        );

        $this->actingAs($this->adminUser)
            ->postJson(route('app.api.image-presets.set-default', $second))
            ->assertOk();

        expect($second->fresh()->is_default)->toBeTrue();
    });
});

describe('Content Images Tab Dropdown', function () {
    it('shows organization presets in the Images tab dropdown', function () {
        $site = ClientSite::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'name' => 'Test Site',
            'type' => 'wordpress',
            'site_url' => 'https://example.com',
            'allowed_domains' => ['example.com'],
            'is_active' => true,
            'status' => 'active',
        ]);

        $content = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Content',
            'type' => 'article',
            'status' => 'draft',
            'source' => 'manual',
        ]);

        $preset = $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'My Custom Preset', 'instructions' => 'Custom style for images'],
            $this->adminUser->id
        );

        $response = $this->actingAs($this->adminUser)
            ->get(route('app.content.show', ['content' => $content, 'tab' => 'images']));

        $response->assertOk()
            ->assertSee('My Custom Preset')
            ->assertSee('(Default)');
    });

    it('shows empty state when no presets exist', function () {
        $site = ClientSite::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'name' => 'Test Site',
            'type' => 'wordpress',
            'site_url' => 'https://example.com',
            'allowed_domains' => ['example.com'],
            'is_active' => true,
            'status' => 'active',
        ]);

        $content = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Content',
            'type' => 'article',
            'status' => 'draft',
            'source' => 'manual',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->get(route('app.content.show', ['content' => $content, 'tab' => 'images']));

        $response->assertOk()
            ->assertSee('No presets available')
            ->assertSee('Create image presets');
    });

    it('does not show presets from other organizations in the dropdown', function () {
        $otherOrg = Organization::create([
            'name' => 'Other Org',
            'slug' => 'other-org-' . Str::lower(Str::random(6)),
            'status' => 'active',
            'approved_at' => now(),
        ]);

        // Create preset for OTHER organization
        $this->presetService->createPreset(
            $otherOrg->id,
            ['name' => 'Other Org Preset', 'instructions' => 'Should not appear'],
        );

        // Create preset for MY organization
        $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'My Org Preset', 'instructions' => 'Should appear'],
            $this->adminUser->id
        );

        $site = ClientSite::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'name' => 'Test Site',
            'type' => 'wordpress',
            'site_url' => 'https://example.com',
            'allowed_domains' => ['example.com'],
            'is_active' => true,
            'status' => 'active',
        ]);

        $content = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Content',
            'type' => 'article',
            'status' => 'draft',
            'source' => 'manual',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->get(route('app.content.show', ['content' => $content, 'tab' => 'images']));

        $response->assertOk()
            ->assertSee('My Org Preset')
            ->assertDontSee('Other Org Preset');
    });

    it('newly created preset appears in dropdown after page reload', function () {
        $site = ClientSite::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'name' => 'Test Site',
            'type' => 'wordpress',
            'site_url' => 'https://example.com',
            'allowed_domains' => ['example.com'],
            'is_active' => true,
            'status' => 'active',
        ]);

        $content = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Content',
            'type' => 'article',
            'status' => 'draft',
            'source' => 'manual',
        ]);

        // First visit - no presets
        $response = $this->actingAs($this->adminUser)
            ->get(route('app.content.show', ['content' => $content, 'tab' => 'images']));

        $response->assertOk()
            ->assertSee('No presets available');

        // Create a preset
        $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'Newly Created Preset', 'instructions' => 'Fresh preset'],
            $this->adminUser->id
        );

        // Second visit - preset should appear
        $response = $this->actingAs($this->adminUser)
            ->get(route('app.content.show', ['content' => $content, 'tab' => 'images']));

        $response->assertOk()
            ->assertSee('Newly Created Preset')
            ->assertDontSee('No presets available');
    });

    it('marks default preset in dropdown label', function () {
        $site = ClientSite::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'name' => 'Test Site',
            'type' => 'wordpress',
            'site_url' => 'https://example.com',
            'allowed_domains' => ['example.com'],
            'is_active' => true,
            'status' => 'active',
        ]);

        $content = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Content',
            'type' => 'article',
            'status' => 'draft',
            'source' => 'manual',
        ]);

        $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'The Default', 'instructions' => 'Default style'],
            $this->adminUser->id
        );

        $this->presetService->createPreset(
            $this->organization->id,
            ['name' => 'Not Default', 'instructions' => 'Custom style'],
            $this->adminUser->id
        );

        $response = $this->actingAs($this->adminUser)
            ->get(route('app.content.show', ['content' => $content, 'tab' => 'images']));

        $response->assertOk()
            ->assertSee('The Default (Default)')
            ->assertSee('Not Default')
            ->assertDontSee('Not Default (Default)');
    });
});
