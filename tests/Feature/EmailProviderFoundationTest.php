<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\EmailProvider;
use App\Models\Newsletter;
use App\Models\Role;
use App\Models\User;
use App\Services\EmailProviderManager;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class EmailProviderFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_provider_can_be_created_with_encrypted_credentials(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');

        $this->actingAs($user)
            ->post(route('settings.email-providers.store'), [
                'scope' => 'brand',
                'provider' => 'mailgun',
                'name' => 'Primary Mailgun',
                'status' => 'active',
                'from_email' => 'newsletter@example.com',
                'from_name' => 'Argusly',
                'credential_label' => 'API key',
                'secret' => 'super-secret-token',
            ])
            ->assertRedirect(route('settings.email-providers'));

        $provider = EmailProvider::query()->where('name', 'Primary Mailgun')->firstOrFail();
        $rawCredentials = DB::table('email_providers')->where('id', $provider->id)->value('credentials');

        $this->assertSame($account->id, $provider->account_id);
        $this->assertSame($brand->id, $provider->brand_id);
        $this->assertSame('mailgun', $provider->provider);
        $this->assertSame('newsletter@example.com', $provider->settings['from_email']);
        $this->assertSame('super-secret-token', $provider->credentials['secret']);
        $this->assertStringNotContainsString('super-secret-token', (string) $rawCredentials);

        $this->actingAs($user)
            ->get(route('settings.email-providers'))
            ->assertOk()
            ->assertSee('Email providers')
            ->assertSee('Primary Mailgun')
            ->assertSee('newsletter@example.com');
    }

    public function test_fake_provider_can_send_test_email_and_marks_provider_verified(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $provider = $this->provider($account, $brand);

        $this->actingAs($user)
            ->post(route('settings.email-providers.test', $provider), [
                'to' => 'test@example.com',
            ])
            ->assertRedirect(route('settings.email-providers'));

        $provider->refresh();
        $this->assertSame('active', $provider->status);
        $this->assertNotNull($provider->last_verified_at);

        $result = app(EmailProviderManager::class)->sendTestEmail($provider, 'another@example.com');
        $this->assertTrue($result['ok']);
        $this->assertSame('smtp', $result['provider']);
        $this->assertSame('another@example.com', $result['to']);
        $this->assertStringStartsWith('fake_', $result['message_id']);
    }

    public function test_email_provider_settings_are_tenant_safe(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        [, $otherAccount, $otherBrand] = $this->tenantUser('owner', slug: 'hidden-email-provider-account');

        $visible = $this->provider($account, $brand, 'Visible provider');
        $hidden = $this->provider($otherAccount, $otherBrand, 'Hidden provider');

        $this->actingAs($user)
            ->get(route('settings.email-providers'))
            ->assertOk()
            ->assertSee($visible->name)
            ->assertDontSee($hidden->name);

        $this->actingAs($user)
            ->post(route('settings.email-providers.test', $hidden), [
                'to' => 'test@example.com',
            ])
            ->assertNotFound();
    }

    public function test_email_provider_rejects_invalid_provider_and_cross_account_brand(): void
    {
        [, $account] = $this->tenantUser('owner');
        [, , $otherBrand] = $this->tenantUser('owner', slug: 'foreign-email-brand');

        $this->expectException(InvalidArgumentException::class);

        EmailProvider::query()->create([
            'account_id' => $account->id,
            'brand_id' => $otherBrand->id,
            'provider' => 'mailgun',
            'name' => 'Cross tenant provider',
            'status' => 'active',
        ]);
    }

    public function test_newsletters_can_reference_same_tenant_email_provider_for_later_selection(): void
    {
        [, $account, $brand] = $this->tenantUser('owner');
        [, $otherAccount, $otherBrand] = $this->tenantUser('owner', slug: 'other-email-provider-account');
        $provider = $this->provider($account, $brand);
        $otherProvider = $this->provider($otherAccount, $otherBrand);

        $newsletter = Newsletter::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'email_provider_id' => $provider->id,
            'title' => 'Provider-ready newsletter',
            'language' => 'en',
            'status' => 'draft',
        ]);

        $this->assertSame($provider->id, $newsletter->emailProvider->id);

        $this->expectException(InvalidArgumentException::class);

        Newsletter::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'email_provider_id' => $otherProvider->id,
            'title' => 'Invalid provider newsletter',
            'language' => 'en',
            'status' => 'draft',
        ]);
    }

    /**
     * @return array{User, Account, Brand}
     */
    private function tenantUser(string $roleName, string $slug = 'email-provider-account'): array
    {
        $this->seed(LanguageSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => str($slug)->headline(), 'slug' => fake()->unique()->slug()]);
        $brand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => str($slug)->headline().' Brand',
            'slug' => fake()->unique()->slug(),
            'default_content_language' => 'en',
            'enabled_content_languages' => ['en', 'nl'],
        ]);

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail(), ['account_id' => $account->id]);
        app(SubscriptionService::class)->activatePlan($account, 'growth_monthly');

        return [$user, $account, $brand];
    }

    private function provider(Account $account, Brand $brand, string $name = 'SMTP placeholder'): EmailProvider
    {
        return EmailProvider::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'provider' => 'smtp',
            'name' => $name,
            'status' => 'active',
            'settings' => ['from_email' => 'newsletter@example.com'],
            'credentials' => ['secret' => 'placeholder-secret'],
        ]);
    }
}
