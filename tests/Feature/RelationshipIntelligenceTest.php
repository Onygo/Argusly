<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\RelationshipIntelligenceService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelationshipIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_contacts_organizations_and_relationships_can_be_created(): void
    {
        [$user, $account] = $this->tenantUser('owner');

        $this->actingAs($user)
            ->post(route('app.relationships.contacts.store'), [
                'first_name' => 'Maya',
                'last_name' => 'Chen',
                'email' => 'maya@example.com',
                'linkedin_url' => 'https://linkedin.com/in/maya',
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('app.relationships.organizations.store'), [
                'name' => 'Industry Weekly',
                'website' => 'https://industry.example',
                'industry' => 'Media',
            ])
            ->assertRedirect();

        $contact = Contact::query()->where('email', 'maya@example.com')->firstOrFail();
        $organization = Organization::query()->where('name', 'Industry Weekly')->firstOrFail();

        $this->actingAs($user)
            ->post(route('app.relationships.edges.store'), [
                'from_type' => 'contact',
                'from_id' => $contact->id,
                'to_type' => 'organization',
                'to_id' => $organization->id,
                'relationship_type' => 'journalist',
                'strength' => 82,
            ])
            ->assertRedirect(route('app.relationships'));

        $this->assertDatabaseHas('relationships', [
            'account_id' => $account->id,
            'from_type' => Contact::class,
            'from_id' => $contact->id,
            'to_type' => Organization::class,
            'to_id' => $organization->id,
            'relationship_type' => 'journalist',
            'strength' => 82,
        ]);
    }

    public function test_relationship_graph_and_detail_pages_are_tenant_safe(): void
    {
        [$user, $account] = $this->tenantUser('owner');
        $visible = Contact::query()->create([
            'account_id' => $account->id,
            'first_name' => 'Visible',
            'last_name' => 'Expert',
            'email' => 'visible@example.com',
        ]);

        $otherAccount = Account::query()->create(['name' => 'Other Account', 'slug' => 'other-account']);
        $hidden = Contact::query()->create([
            'account_id' => $otherAccount->id,
            'first_name' => 'Hidden',
            'last_name' => 'Analyst',
            'email' => 'hidden@example.com',
        ]);

        $this->actingAs($user)
            ->get(route('app.relationships'))
            ->assertOk()
            ->assertSee('Relationship Graph')
            ->assertSee($visible->display_name)
            ->assertDontSee($hidden->display_name);

        $this->actingAs($user)
            ->get(route('app.relationships.contacts.show', $hidden))
            ->assertForbidden();
    }

    public function test_service_rejects_cross_account_edges_and_detail_shows_relationships(): void
    {
        [$user, $account] = $this->tenantUser('owner');
        $contact = Contact::query()->create([
            'account_id' => $account->id,
            'first_name' => 'Nora',
            'last_name' => 'Field',
        ]);
        $organization = Organization::query()->create([
            'account_id' => $account->id,
            'name' => 'Expert Network',
            'industry' => 'Analyst Relations',
        ]);
        $otherAccount = Account::query()->create(['name' => 'Other Account', 'slug' => 'other-account']);
        $foreignOrganization = Organization::query()->create([
            'account_id' => $otherAccount->id,
            'name' => 'Foreign Media',
        ]);

        app(RelationshipIntelligenceService::class)->createRelationship($account, [
            'from_type' => 'contact',
            'from_id' => $contact->id,
            'to_type' => 'organization',
            'to_id' => $organization->id,
            'relationship_type' => 'expert',
        ]);

        $this->actingAs($user)
            ->get(route('app.relationships.contacts.show', $contact))
            ->assertOk()
            ->assertSee('Nora Field')
            ->assertSee('Expert Network')
            ->assertSee('Expert');

        $this->expectException(ModelNotFoundException::class);

        app(RelationshipIntelligenceService::class)->createRelationship($account, [
            'from_type' => 'contact',
            'from_id' => $contact->id,
            'to_type' => 'organization',
            'to_id' => $foreignOrganization->id,
            'relationship_type' => 'media',
        ]);
    }

    /**
     * @return array{User, Account, Brand}
     */
    private function tenantUser(string $roleName): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Relationship Account', 'slug' => 'relationship-account']);
        $brand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => 'Relationship Brand',
            'slug' => 'relationship-brand',
        ]);

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail(), ['account_id' => $account->id]);

        app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');

        return [$user, $account, $brand];
    }
}
