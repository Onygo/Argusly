<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Role;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_form_can_be_rendered(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Sign in to continue to your workspace.')
            ->assertSee('you@company.com')
            ->assertDontSee('alpha.owner@example.com')
            ->assertSee(route('marketing.home'), false)
            ->assertSee(route('marketing.signup'), false)
            ->assertSee(route('marketing.page', 'privacy'), false)
            ->assertSee(route('marketing.page', 'terms'), false)
            ->assertSee('Back to the marketing site')
            ->assertDontSee('Continue with Google');
    }

    public function test_user_can_login_and_logout(): void
    {
        $user = $this->tenantUser();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);

        $this->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        $user = $this->tenantUser();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    private function tenantUser(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::query()->create([
            'name' => 'Alpha Owner',
            'email' => 'alpha.owner@example.com',
            'password' => Hash::make('password'),
        ]);
        $account = Account::query()->create(['name' => 'Alpha Agency', 'slug' => 'alpha-agency']);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Alpha Main', 'slug' => 'alpha-main']);

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach(Role::query()->where('name', 'owner')->firstOrFail(), ['account_id' => $account->id]);

        app(SubscriptionService::class)->activatePlan($account, 'growth_monthly');

        return $user;
    }
}
