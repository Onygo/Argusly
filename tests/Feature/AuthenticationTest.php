<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Role;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
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
            ->assertSee(route('password.request'), false)
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

    public function test_password_reset_link_form_can_be_rendered(): void
    {
        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('Reset your password')
            ->assertSee('Send reset link')
            ->assertSee(route('password.email'), false)
            ->assertSee(route('login'), false);
    }

    public function test_password_reset_link_can_be_requested(): void
    {
        Notification::fake();

        $user = $this->tenantUser();

        $this->post(route('password.email'), [
            'email' => $user->email,
        ])->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_password_reset_notification_uses_argusly_mail_theme(): void
    {
        $user = $this->tenantUser();
        $notification = new ResetPassword('reset-token');

        $html = $notification->toMail($user)->render();

        $this->assertStringContainsString('Argusly account', $html);
        $this->assertStringContainsString('border-radius: 20px', $html);
        $this->assertStringContainsString('background-color: #235cff', $html);
        $this->assertStringContainsString('Reset Password', $html);
    }

    public function test_user_can_reset_password_with_valid_token(): void
    {
        $user = $this->tenantUser();
        $token = Password::createToken($user);

        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->assertOk()
            ->assertSee('Choose a new password')
            ->assertSee($user->email);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'fresh-secure-password',
            'password_confirmation' => 'fresh-secure-password',
        ])->assertRedirect(route('login'))
            ->assertSessionHas('status');

        $this->assertTrue(Hash::check('fresh-secure-password', $user->refresh()->password));

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'fresh-secure-password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_authenticated_user_can_update_password(): void
    {
        $user = $this->tenantUser();

        $this->actingAs($user)
            ->get(route('settings.profile'))
            ->assertOk()
            ->assertSee('Update password');

        $this->actingAs($user)
            ->patch(route('settings.profile.password.update'), [
                'current_password' => 'password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ])
            ->assertRedirect(route('settings.profile'))
            ->assertSessionHas('status', 'Password updated.');

        $this->assertTrue(Hash::check('new-secure-password', $user->refresh()->password));
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
