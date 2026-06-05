<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Audience;
use App\Models\Brand;
use App\Models\BrandNarrative;
use App\Models\BrandProduct;
use App\Models\BrandProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\BrandKnowledgeCenterService;
use App\Services\CreditService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Tests\TestCase;

class BrandKnowledgeCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_manage_current_brand_knowledge_center(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');

        $this->actingAs($user)
            ->patch(route('settings.knowledge-center.profile.update'), [
                'official_name' => 'Argusly',
                'tagline' => 'Be found by AI.',
                'short_description' => 'AI visibility platform.',
                'long_description' => 'Argusly helps brands understand and improve how AI systems represent them.',
                'mission' => 'Make brands machine-understandable.',
                'vision' => 'Every important brand has an AI-ready source of truth.',
                'positioning' => 'AI visibility operating system.',
                'value_proposition' => 'Unify brand knowledge for recommendations and generation.',
                'tone_of_voice' => 'Clear, expert and pragmatic.',
                'primary_audience' => 'Marketing leaders.',
                'secondary_audience' => 'Content and communications teams.',
                'website' => 'https://argusly.example',
            ])
            ->assertRedirect(route('settings.knowledge-center'));

        $this->actingAs($user)
            ->post(route('settings.knowledge-center.products.store'), [
                'name' => 'AI Visibility Monitor',
                'description' => 'Tracks answer engine visibility.',
                'category' => 'Visibility',
                'website' => 'https://argusly.example/visibility',
                'status' => 'active',
            ])
            ->assertRedirect(route('settings.knowledge-center'));

        $this->actingAs($user)
            ->post(route('settings.knowledge-center.services.store'), [
                'name' => 'Narrative Audit',
                'description' => 'Maps gaps in brand representation.',
                'category' => 'Strategy',
                'status' => 'draft',
            ])
            ->assertRedirect(route('settings.knowledge-center'));

        $this->actingAs($user)
            ->post(route('settings.knowledge-center.narratives.store'), [
                'title' => 'AI-first brand memory',
                'description' => 'The brand source of truth should be usable by humans and agents.',
                'importance' => 'high',
                'status' => 'active',
            ])
            ->assertRedirect(route('settings.knowledge-center'));

        $this->assertDatabaseHas('brand_profiles', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'official_name' => 'Argusly',
        ]);
        $this->assertDatabaseHas('brand_products', ['account_id' => $account->id, 'brand_id' => $brand->id, 'name' => 'AI Visibility Monitor']);
        $this->assertDatabaseHas('brand_services', ['account_id' => $account->id, 'brand_id' => $brand->id, 'name' => 'Narrative Audit']);
        $this->assertDatabaseHas('brand_narratives', ['account_id' => $account->id, 'brand_id' => $brand->id, 'title' => 'AI-first brand memory']);

        $this->actingAs($user)
            ->get(route('settings.knowledge-center'))
            ->assertOk()
            ->assertSee('Knowledge Center')
            ->assertSee('Brand Profile Completeness')
            ->assertSee('AI Visibility Monitor')
            ->assertSee('Narrative Audit')
            ->assertSee('AI-first brand memory')
            ->assertSee('Creator matching')
            ->assertSee('Relationship intelligence');
    }

    public function test_editor_can_access_brand_voice_from_content_navigation(): void
    {
        [$editor, $account, $brand] = $this->tenantWithRole('editor');

        $this->actingAs($editor)
            ->get(route('app.content.brand-voice'))
            ->assertOk()
            ->assertSee('Brand Voice')
            ->assertSee('Tone of voice')
            ->assertSee('Primary audience');

        $this->actingAs($editor)
            ->patch(route('app.content.brand-voice.profile.update'), [
                'official_name' => 'Alpha Brand',
                'tagline' => 'Be answer-ready.',
                'short_description' => 'A practical content operations brand.',
                'long_description' => null,
                'mission' => null,
                'vision' => null,
                'positioning' => null,
                'value_proposition' => null,
                'tone_of_voice' => 'Sharp, useful and direct.',
                'primary_audience' => 'Marketing teams and editors.',
                'secondary_audience' => 'Agency strategists.',
                'website' => null,
            ])
            ->assertRedirect(route('app.content.brand-voice'));

        $this->assertDatabaseHas('brand_profiles', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'tone_of_voice' => 'Sharp, useful and direct.',
            'primary_audience' => 'Marketing teams and editors.',
        ]);
    }

    public function test_ai_setup_can_generate_preview_and_apply_to_argusly_structures(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');

        app(CreditService::class)->grant($account, 1000, $user, 'Brand setup generation test credits');

        $this->actingAs($user)
            ->post(route('settings.knowledge-center.setup.generate'), [
                'input_method' => 'paste_text',
                'source_text' => 'Argusly helps marketing teams understand and improve how AI systems describe their brand.',
                'sections' => ['company_profile', 'brand_voices', 'buyer_personas', 'team_personas'],
            ])
            ->assertRedirect(route('settings.knowledge-center'))
            ->assertSessionHas('brand_setup_preview');

        $payload = [
            'company_profile' => [
                'official_name' => 'Argusly',
                'tagline' => 'Be found by AI.',
                'short_description' => 'AI visibility platform.',
                'long_description' => 'Argusly helps brands improve representation in answer engines.',
                'mission' => 'Make brands machine-understandable.',
                'vision' => 'Every brand has an AI-ready source of truth.',
                'positioning' => 'AI visibility operating system.',
                'value_proposition' => 'Centralize brand knowledge for content and visibility workflows.',
                'tone_of_voice' => 'Clear and pragmatic.',
                'primary_audience' => 'Marketing leaders.',
                'secondary_audience' => 'Content teams.',
                'website' => 'https://argusly.example',
            ],
            'brand_voices' => [
                [
                    'name' => 'Expert operator',
                    'description' => 'Specific, practical and evidence-led.',
                    'do' => ['Use concrete examples'],
                    'dont' => ['Use generic claims'],
                ],
            ],
            'buyer_personas' => [
                [
                    'name' => 'Marketing leader',
                    'description' => 'Owns visibility and content outcomes.',
                    'needs' => ['Clear priorities'],
                    'pain_points' => ['Fragmented brand knowledge'],
                ],
            ],
            'team_personas' => [
                [
                    'name' => 'Founder spokesperson',
                    'description' => 'Explains the market point of view.',
                    'role' => 'Founder',
                    'expertise' => ['Strategy'],
                ],
            ],
        ];

        $this->actingAs($user)
            ->post(route('settings.knowledge-center.setup.apply'), [
                'payload' => json_encode($payload),
                'sections' => ['company_profile', 'brand_voices', 'buyer_personas', 'team_personas'],
            ])
            ->assertRedirect(route('settings.knowledge-center'));

        $this->assertDatabaseHas('brand_profiles', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'official_name' => 'Argusly',
            'primary_audience' => 'Marketing leaders.',
        ]);
        $this->assertDatabaseHas('brand_narratives', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Brand voice: Expert operator',
        ]);
        $this->assertDatabaseHas('audiences', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Marketing leader',
        ]);
        $this->assertDatabaseHas('audiences', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Founder spokesperson',
        ]);

        $this->assertSame(1, BrandNarrative::query()->where('brand_id', $brand->id)->count());
        $this->assertSame(2, Audience::query()->where('brand_id', $brand->id)->count());
    }

    public function test_old_signals_route_redirects_to_intelligence_feed(): void
    {
        [$editor] = $this->tenantWithRole('editor');

        $this->actingAs($editor)
            ->get(route('app.intelligence.signals'))
            ->assertRedirect(route('app.intelligence'));
    }

    public function test_knowledge_center_is_one_profile_per_brand_and_brand_scoped(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $otherBrand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Other Brand', 'slug' => 'other-brand']);
        $otherAccount = Account::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $foreignBrand = Brand::query()->create(['account_id' => $otherAccount->id, 'name' => 'Foreign Brand', 'slug' => 'foreign-brand']);
        $service = app(BrandKnowledgeCenterService::class);

        $firstProfile = $service->profileForBrand($account, $brand);
        $secondProfile = $service->profileForBrand($account, $brand);

        BrandProduct::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Visible Product',
            'status' => 'active',
        ]);
        BrandProduct::query()->create([
            'account_id' => $account->id,
            'brand_id' => $otherBrand->id,
            'name' => 'Hidden Same Account Product',
            'status' => 'active',
        ]);
        BrandProduct::query()->create([
            'account_id' => $otherAccount->id,
            'brand_id' => $foreignBrand->id,
            'name' => 'Hidden Other Account Product',
            'status' => 'active',
        ]);

        $this->assertTrue($firstProfile->is($secondProfile));
        $this->assertSame(1, BrandProfile::query()->where('brand_id', $brand->id)->count());

        $this->actingAs($user)
            ->get(route('settings.knowledge-center'))
            ->assertOk()
            ->assertSee('Visible Product')
            ->assertDontSee('Hidden Same Account Product')
            ->assertDontSee('Hidden Other Account Product');
    }

    public function test_completeness_recommendations_appear_on_dashboard(): void
    {
        [$user] = $this->tenantWithRole('owner');

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Brand Profile Completeness')
            ->assertSee('Complete Tagline')
            ->assertSee('Open Knowledge Center');
    }

    public function test_policies_authorize_tenant_models(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');

        $profile = app(BrandKnowledgeCenterService::class)->profileForBrand($account, $brand);
        $product = BrandProduct::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Policy Product',
            'status' => 'active',
        ]);

        $this->assertTrue(Gate::forUser($user)->allows('update', $profile));
        $this->assertTrue(Gate::forUser($user)->allows('view', $product));
    }

    public function test_cross_account_brand_cannot_be_used_for_knowledge_center(): void
    {
        [, $account] = $this->tenantWithRole('owner');
        $otherAccount = Account::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $foreignBrand = Brand::query()->create(['account_id' => $otherAccount->id, 'name' => 'Foreign Brand', 'slug' => 'foreign-brand']);

        $this->expectException(InvalidArgumentException::class);

        app(BrandKnowledgeCenterService::class)->profileForBrand($account, $foreignBrand);
    }

    /**
     * @return array{0: User, 1: Account, 2: Brand}
     */
    private function tenantWithRole(string $roleName): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Alpha Account', 'slug' => fake()->unique()->slug()]);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Alpha Brand', 'slug' => fake()->unique()->slug()]);
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);
        app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');

        return [$user, $account, $brand];
    }
}
