<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Audience;
use App\Models\AudienceMember;
use App\Models\Brand;
use App\Models\Contact;
use App\Models\Role;
use App\Models\Segment;
use App\Models\User;
use App\Services\AudienceService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class AudienceFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_audience_can_be_created_and_reuses_existing_contact_for_member(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $contact = Contact::query()->create([
            'account_id' => $account->id,
            'first_name' => 'Maya',
            'last_name' => 'Chen',
            'email' => 'maya@example.com',
        ]);

        $this->actingAs($user)
            ->post(route('app.audiences.store'), [
                'scope' => 'brand',
                'name' => 'AI visibility readers',
                'description' => 'People interested in AI visibility.',
                'status' => 'active',
            ])
            ->assertRedirect();

        $audience = Audience::query()->where('name', 'AI visibility readers')->firstOrFail();

        $this->actingAs($user)
            ->post(route('app.audiences.members.store', $audience), [
                'email' => 'maya@example.com',
                'status' => 'active',
                'source' => 'manual',
            ])
            ->assertRedirect(route('app.audiences.show', $audience));

        $member = AudienceMember::query()->where('audience_id', $audience->id)->firstOrFail();

        $this->assertSame($account->id, $audience->account_id);
        $this->assertSame($brand->id, $audience->brand_id);
        $this->assertSame($contact->id, $member->contact_id);
        $this->assertSame('Maya', $member->first_name);
        $this->assertSame('Chen', $member->last_name);

        $this->actingAs($user)
            ->get(route('app.audiences.show', $audience))
            ->assertOk()
            ->assertSee('AI visibility readers')
            ->assertSee('maya@example.com')
            ->assertSee('Contact');
    }

    public function test_audience_member_rejects_cross_account_contact(): void
    {
        [, $account, $brand] = $this->tenantUser('owner');
        [, $otherAccount] = $this->tenantUser('owner', activatePlan: true, slug: 'other-audience-account');
        $audience = $this->audience($account, $brand);
        $contact = Contact::query()->create([
            'account_id' => $otherAccount->id,
            'first_name' => 'Hidden',
            'last_name' => 'Contact',
            'email' => 'hidden@example.com',
        ]);

        $this->expectException(InvalidArgumentException::class);

        AudienceMember::query()->create([
            'account_id' => $account->id,
            'audience_id' => $audience->id,
            'contact_id' => $contact->id,
            'email' => 'hidden@example.com',
            'status' => 'active',
        ]);
    }

    public function test_segments_can_attach_to_audience_with_json_rules(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $audience = $this->audience($account, $brand, 'Newsletter subscribers');

        $this->actingAs($user)
            ->post(route('app.segments.store'), [
                'scope' => 'brand',
                'audience_id' => $audience->id,
                'name' => 'Active subscribers',
                'description' => 'Members available for future activation.',
                'rules_json' => '{"field":"status","operator":"equals","value":"active"}',
                'status' => 'active',
            ])
            ->assertRedirect(route('app.audiences'));

        $segment = Segment::query()->where('name', 'Active subscribers')->firstOrFail();

        $this->assertSame($account->id, $segment->account_id);
        $this->assertSame($brand->id, $segment->brand_id);
        $this->assertSame($audience->id, $segment->audience_id);
        $this->assertSame('status', $segment->rules['field']);
    }

    public function test_audience_index_detail_and_segments_are_tenant_safe(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        [, $otherAccount, $otherBrand] = $this->tenantUser('owner', activatePlan: true, slug: 'hidden-audience-account');

        $visible = $this->audience($account, $brand, 'Visible audience');
        $hidden = $this->audience($otherAccount, $otherBrand, 'Hidden audience');
        Segment::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'audience_id' => $visible->id,
            'name' => 'Visible segment',
            'status' => 'active',
        ]);
        Segment::query()->create([
            'account_id' => $otherAccount->id,
            'brand_id' => $otherBrand->id,
            'audience_id' => $hidden->id,
            'name' => 'Hidden segment',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('app.audiences'))
            ->assertOk()
            ->assertSee('Audiences')
            ->assertSee('Visible audience')
            ->assertSee('Visible segment')
            ->assertDontSee('Hidden audience')
            ->assertDontSee('Hidden segment');

        $this->actingAs($user)
            ->get(route('app.audiences.show', $hidden))
            ->assertForbidden();
    }

    public function test_audiences_are_module_gated_and_visible_in_navigation(): void
    {
        [$user] = $this->tenantUser('owner');
        [$unsubscribedUser] = $this->tenantUser('owner', activatePlan: false, slug: 'audience-core-only');

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Marketing')
            ->assertSee('Audiences');

        $this->actingAs($unsubscribedUser)
            ->get(route('app.audiences'))
            ->assertForbidden();
    }

    public function test_service_does_not_create_contacts_or_send_email_when_adding_standalone_member(): void
    {
        [, $account, $brand] = $this->tenantUser('owner');
        $audience = $this->audience($account, $brand);

        app(AudienceService::class)->addMember($audience, [
            'email' => 'standalone@example.com',
            'first_name' => 'Standalone',
            'status' => 'active',
        ]);

        $this->assertDatabaseMissing('contacts', [
            'account_id' => $account->id,
            'email' => 'standalone@example.com',
        ]);
        $this->assertDatabaseHas('audience_members', [
            'account_id' => $account->id,
            'audience_id' => $audience->id,
            'contact_id' => null,
            'email' => 'standalone@example.com',
        ]);
    }

    /**
     * @return array{User, Account, Brand}
     */
    private function tenantUser(string $roleName, bool $activatePlan = true, string $slug = 'audience-account'): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => str($slug)->headline(), 'slug' => fake()->unique()->slug()]);
        $brand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => str($slug)->headline().' Brand',
            'slug' => fake()->unique()->slug(),
        ]);

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail(), ['account_id' => $account->id]);

        if ($activatePlan) {
            app(SubscriptionService::class)->activatePlan($account, 'growth_monthly');
        }

        return [$user, $account, $brand];
    }

    private function audience(Account $account, Brand $brand, string $name = 'Brand audience'): Audience
    {
        return Audience::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => $name,
            'status' => 'active',
        ]);
    }
}
