<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\ContentAsset;
use App\Models\ContentTranslation;
use App\Models\DomainEvent;
use App\Models\GeneratedAsset;
use App\Models\Role;
use App\Models\User;
use App\Services\ContentAuditService;
use App\Services\ContentLifecycleService;
use App\Services\CreditService;
use App\Services\PublishingService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

class ContentEngineLanguageRetrofitTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_language_filter_and_generation_language_selection_use_brand_enabled_languages(): void
    {
        Queue::fake();

        [$user, , $brand] = $this->tenantWithRole('editor');
        $brand->update(['enabled_content_languages' => ['en', 'de'], 'default_content_language' => 'en']);

        $english = ContentAsset::factory()->forBrand($brand)->create(['title' => 'English asset', 'language' => 'en', 'locale' => 'en_US']);
        ContentAsset::factory()->forBrand($brand)->create(['title' => 'German asset', 'language' => 'de', 'locale' => 'de_DE']);

        $this->actingAs($user)
            ->get(route('app.content.index', ['language' => 'de']))
            ->assertOk()
            ->assertSee('German asset')
            ->assertDontSee('English asset');

        $this->actingAs($user)
            ->get(route('app.content.show', $english))
            ->assertOk()
            ->assertSee('English')
            ->assertSee('German')
            ->assertDontSee('French');

        $this->actingAs($user)
            ->post(route('app.content.generate', $english), [
                'type' => 'refresh',
                'language' => 'de',
            ])
            ->assertRedirect(route('app.content.show', $english));

        $run = GeneratedAsset::query()->where('content_asset_id', $english->id)->firstOrFail();

        $this->assertSame('de', $run->language);
        $this->assertSame('de_DE', $run->locale);
    }

    public function test_content_asset_language_must_be_brand_enabled_content_language(): void
    {
        [, , $brand] = $this->tenantWithRole('editor');
        $brand->update(['enabled_content_languages' => ['en', 'nl']]);

        $this->expectException(InvalidArgumentException::class);

        ContentAsset::factory()->forBrand($brand)->create([
            'language' => 'fr',
            'locale' => 'fr_FR',
        ]);
    }

    public function test_audit_lifecycle_and_publishing_store_language_context(): void
    {
        Queue::fake();

        [$user, , $brand] = $this->tenantWithRole('publisher');
        $asset = ContentAsset::factory()->forBrand($brand)->create([
            'status' => 'approved',
            'language' => 'nl',
            'locale' => 'nl_NL',
            'body' => "## Kop\n\nDit is Nederlandse content met voldoende woorden voor een controle en lifecycle score.",
        ]);

        $audit = app(ContentAuditService::class)->requestForContentAsset($asset, $user);
        $audit = app(ContentAuditService::class)->run($audit);
        $score = app(ContentLifecycleService::class)->calculateForContentAsset($asset);
        $action = app(PublishingService::class)->request($asset, $user, ['action' => 'publish']);

        $this->assertSame('nl', $audit->language);
        $this->assertSame('nl_NL', $audit->locale);
        $this->assertStringContainsString('nl audit', $audit->summary);
        $this->assertSame('nl', $score->language);
        $this->assertSame('nl_NL', $score->locale);
        $this->assertSame('nl', $action->language);
        $this->assertSame('nl_NL', $action->locale);
        $this->assertSame('nl', $action->request_payload['language']);
        $this->assertSame('nl_NL', $action->request_payload['locale']);
        $this->assertSame('nl', $action->request_payload['content']['language']);
        $this->assertSame('nl_NL', $action->request_payload['content']['locale']);
    }

    public function test_translation_flow_excludes_source_language_creates_linked_drafts_charges_each_target_and_emits_events(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('editor');
        $brand->update(['enabled_content_languages' => ['en', 'nl', 'de'], 'default_content_language' => 'en']);
        $asset = ContentAsset::factory()->forBrand($brand)->create([
            'title' => 'Translation source',
            'language' => 'en',
            'locale' => 'en_US',
        ]);

        $this->actingAs($user)
            ->get(route('app.content.show', $asset))
            ->assertOk()
            ->assertDontSee('English · English')
            ->assertSee('Dutch')
            ->assertSee('German');

        $this->actingAs($user)
            ->post(route('app.content.translations.store', $asset), [
                'target_languages' => ['nl', 'de'],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('app.content.show', $asset));

        $this->assertSame(800, app(CreditService::class)->balance($account));
        $this->assertSame(2, ContentTranslation::query()->where('source_content_asset_id', $asset->id)->count());

        $translation = ContentTranslation::query()->where('target_language', 'nl')->firstOrFail();
        $translated = $translation->translatedContentAsset;

        $this->assertNotNull($translated);
        $this->assertSame('draft', $translated->status);
        $this->assertSame('nl', $translated->language);
        $this->assertSame('nl_NL', $translated->locale);
        $this->assertSame($asset->id, $translation->source_content_asset_id);
        $this->assertSame($translated->id, $translation->translated_content_asset_id);

        $this->assertDatabaseHas('domain_events', [
            'event_type' => 'ContentTranslationRequested',
            'subject_id' => $translation->id,
        ]);
        $this->assertDatabaseHas('domain_events', [
            'event_type' => 'ContentAssetTranslationCreated',
            'subject_id' => $translated->id,
        ]);
    }

    public function test_translation_flow_rejects_source_language_and_duplicate_active_target(): void
    {
        [$user, , $brand] = $this->tenantWithRole('editor');
        $brand->update(['enabled_content_languages' => ['en', 'nl']]);
        $asset = ContentAsset::factory()->forBrand($brand)->create(['language' => 'en', 'locale' => 'en_US']);

        $this->actingAs($user)
            ->post(route('app.content.translations.store', $asset), [
                'target_languages' => ['en'],
            ])
            ->assertSessionHasErrors('target_languages.0');

        $this->actingAs($user)
            ->post(route('app.content.translations.store', $asset), [
                'target_languages' => ['nl'],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('app.content.show', $asset));

        $this->actingAs($user)
            ->post(route('app.content.translations.store', $asset), [
                'target_languages' => ['nl'],
            ])
            ->assertSessionHasErrors('translations');

        $this->assertSame(1, ContentTranslation::query()->where('source_content_asset_id', $asset->id)->count());
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'ContentTranslationRequested')->count());
    }

    /**
     * @return array{0: User, 1: Account, 2: Brand}
     */
    private function tenantWithRole(string $roleName): array
    {
        $this->seed(LanguageSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create([
            'name' => 'Content Language Account',
            'slug' => fake()->unique()->slug(),
            'default_locale' => 'en',
            'default_content_language' => 'en',
        ]);
        $brand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => 'Content Language Brand',
            'slug' => fake()->unique()->slug(),
            'default_content_language' => 'en',
            'enabled_content_languages' => ['en', 'nl', 'de', 'fr', 'es'],
        ]);
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);

        app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');
        app(CreditService::class)->grant($account, 1000, $user, 'Test credits');

        return [$user, $account, $brand];
    }
}
