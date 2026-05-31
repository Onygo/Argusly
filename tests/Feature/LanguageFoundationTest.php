<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Role;
use App\Models\User;
use App\Services\ContentLanguageService;
use App\Services\LanguageService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LanguageFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_language_seed_defines_separate_ui_and_content_languages(): void
    {
        $this->seed(LanguageSeeder::class);

        $this->assertSame(['en', 'nl'], app(LanguageService::class)->uiCodes());
        $this->assertSame(['en', 'nl', 'de', 'fr', 'es'], app(LanguageService::class)->contentCodes());

        $this->assertDatabaseHas('languages', [
            'code' => 'de',
            'name' => 'German',
            'native_name' => 'Deutsch',
            'is_ui_enabled' => false,
            'is_content_enabled' => true,
        ]);
    }

    public function test_ui_locale_prefers_user_then_account_then_fallback_without_using_content_only_languages(): void
    {
        $this->seed(LanguageSeeder::class);

        $account = Account::query()->create([
            'name' => 'Locale Account',
            'slug' => 'locale-account',
            'default_locale' => 'nl',
            'default_content_language' => 'de',
        ]);
        $user = User::factory()->create(['locale' => 'de']);

        $this->assertSame('nl', app(LanguageService::class)->resolveUiLocale($user, $account));

        $user->update(['locale' => 'en']);

        $this->assertSame('en', app(LanguageService::class)->resolveUiLocale($user->refresh(), $account));
    }

    public function test_content_language_defaults_to_brand_then_account_then_english(): void
    {
        $this->seed(LanguageSeeder::class);

        $account = Account::query()->create([
            'name' => 'Content Account',
            'slug' => 'content-account',
            'default_locale' => 'nl',
            'default_content_language' => 'nl',
        ]);
        $brand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => 'Content Brand',
            'slug' => 'content-brand',
            'default_content_language' => 'de',
            'enabled_content_languages' => ['en', 'de'],
        ]);

        $service = app(ContentLanguageService::class);

        $this->assertSame('de', $service->defaultFor($brand, $account));

        $brand->update(['default_content_language' => 'fr']);

        $this->assertSame('en', $service->defaultFor($brand->refresh(), $account));
    }

    public function test_content_actions_only_show_and_accept_brand_enabled_content_languages(): void
    {
        [$user, , $brand] = $this->tenantWithRole('owner');

        $brand->update([
            'default_content_language' => 'de',
            'enabled_content_languages' => ['en', 'de'],
        ]);

        $this->actingAs($user)
            ->get(route('app.content.create'))
            ->assertOk()
            ->assertSee('English')
            ->assertSee('Deutsch')
            ->assertDontSee('French')
            ->assertDontSee('Spanish');

        $this->actingAs($user)
            ->post(route('app.content.store'), $this->assetPayload(['language' => 'fr']))
            ->assertSessionHasErrors('language');

        $this->actingAs($user)
            ->post(route('app.content.store'), $this->assetPayload(['language' => 'de']))
            ->assertRedirect();

        $this->assertDatabaseHas('content_assets', [
            'brand_id' => $brand->id,
            'title' => 'Localized content asset',
            'language' => 'de',
        ]);
    }

    public function test_user_can_switch_ui_locale_and_app_falls_back_to_english(): void
    {
        [$user] = $this->tenantWithRole('owner');
        $user->update(['locale' => 'en']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Tenant overview');

        $this->actingAs($user)
            ->post(route('user.locale.update'), ['locale' => 'nl'])
            ->assertRedirect();

        $this->assertSame('nl', $user->refresh()->locale);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Tenantoverzicht')
            ->assertSee('Nederlands');

        $this->actingAs($user)
            ->post(route('user.locale.update'), ['locale' => 'de'])
            ->assertSessionHasErrors('locale');
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
            'name' => 'Language Account',
            'slug' => 'language-account',
            'default_locale' => 'nl',
            'default_content_language' => 'nl',
        ]);
        $brand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => 'Language Brand',
            'slug' => 'language-brand',
            'default_content_language' => 'nl',
        ]);
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);

        app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');

        return [$user, $account, $brand];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function assetPayload(array $overrides = []): array
    {
        return [
            'type' => 'article',
            'status' => 'draft',
            'title' => 'Localized content asset',
            'language' => 'en',
            'locale' => 'en_US',
            'source' => 'manual',
            'excerpt' => 'Placeholder excerpt.',
            'body' => 'Placeholder body.',
            ...$overrides,
        ];
    }
}
