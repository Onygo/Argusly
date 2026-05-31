<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Agent;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Competitor;
use App\Models\Contact;
use App\Models\Mention;
use App\Models\Organization;
use App\Models\Relationship;
use App\Models\Role;
use App\Models\Source;
use App\Models\SourceConnection;
use App\Models\SourceSync;
use App\Models\Topic;
use App\Models\User;
use App\Models\VisibilityCheck;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class FoundationPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_routes_for_foundation_domains_forbid_cross_tenant_records(): void
    {
        [$user, , , $otherAccount, $otherBrand] = $this->tenants();

        $source = Source::query()->create([
            'account_id' => $otherAccount->id,
            'brand_id' => $otherBrand->id,
            'name' => 'Hidden source',
            'type' => 'blog',
            'provider' => 'rss',
            'status' => 'active',
        ]);
        $campaign = Campaign::query()->create([
            'account_id' => $otherAccount->id,
            'brand_id' => $otherBrand->id,
            'name' => 'Hidden campaign',
            'slug' => 'hidden-campaign',
            'status' => 'active',
        ]);
        $topic = Topic::query()->create([
            'account_id' => $otherAccount->id,
            'brand_id' => $otherBrand->id,
            'name' => 'Hidden topic',
            'slug' => 'hidden-topic',
            'status' => 'active',
        ]);
        $mention = Mention::query()->create([
            'account_id' => $otherAccount->id,
            'brand_id' => $otherBrand->id,
            'title' => 'Hidden mention',
        ]);
        $contact = Contact::query()->create([
            'account_id' => $otherAccount->id,
            'first_name' => 'Hidden',
            'last_name' => 'Contact',
        ]);
        $organization = Organization::query()->create([
            'account_id' => $otherAccount->id,
            'name' => 'Hidden Organization',
        ]);

        $this->actingAs($user)->get(route('app.sources.show', $source))->assertForbidden();
        $this->actingAs($user)->get(route('app.campaigns.show', $campaign))->assertForbidden();
        $this->actingAs($user)->get(route('app.topics.show', $topic))->assertForbidden();
        $this->actingAs($user)->get(route('app.mentions.show', $mention))->assertForbidden();
        $this->actingAs($user)->get(route('app.relationships.contacts.show', $contact))->assertForbidden();
        $this->actingAs($user)->get(route('app.relationships.organizations.show', $organization))->assertForbidden();
    }

    public function test_policies_deny_cross_tenant_foundation_models(): void
    {
        [$user, , , $otherAccount, $otherBrand] = $this->tenants();
        $source = Source::query()->create([
            'account_id' => $otherAccount->id,
            'brand_id' => $otherBrand->id,
            'name' => 'Hidden source',
            'type' => 'blog',
            'provider' => 'rss',
            'status' => 'active',
        ]);
        $sourceConnection = SourceConnection::query()->create([
            'source_id' => $source->id,
            'status' => 'configured',
        ]);
        $sourceSync = SourceSync::query()->create([
            'source_id' => $source->id,
            'status' => 'completed',
        ]);
        $competitor = Competitor::query()->create([
            'account_id' => $otherAccount->id,
            'brand_id' => $otherBrand->id,
            'name' => 'Hidden competitor',
            'website' => 'https://competitor.example',
            'status' => 'active',
        ]);
        $visibilityCheck = VisibilityCheck::query()->create([
            'account_id' => $otherAccount->id,
            'brand_id' => $otherBrand->id,
            'provider' => 'Google',
            'query' => 'hidden query',
            'brand' => 'Hidden Brand',
            'status' => 'active',
        ]);
        $contact = Contact::query()->create([
            'account_id' => $otherAccount->id,
            'first_name' => 'Hidden',
            'last_name' => 'Contact',
        ]);
        $organization = Organization::query()->create([
            'account_id' => $otherAccount->id,
            'name' => 'Hidden Organization',
        ]);
        $relationship = Relationship::query()->create([
            'account_id' => $otherAccount->id,
            'from_type' => $contact->getMorphClass(),
            'from_id' => $contact->id,
            'to_type' => $organization->getMorphClass(),
            'to_id' => $organization->id,
            'relationship_type' => 'partner',
        ]);

        $this->assertTrue(Gate::forUser($user)->denies('view', $sourceConnection));
        $this->assertTrue(Gate::forUser($user)->denies('view', $sourceSync));
        $this->assertTrue(Gate::forUser($user)->denies('view', $competitor));
        $this->assertTrue(Gate::forUser($user)->denies('view', $visibilityCheck));
        $this->assertTrue(Gate::forUser($user)->denies('view', $relationship));
        $this->assertTrue(Gate::forUser($user)->denies('viewAny', Agent::class));
    }

    /**
     * @return array{User, Account, Brand, Account, Brand}
     */
    private function tenants(): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Policy Account', 'slug' => 'policy-account']);
        $brand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => 'Policy Brand',
            'slug' => 'policy-brand',
        ]);
        $otherAccount = Account::query()->create(['name' => 'Other Account', 'slug' => 'other-policy-account']);
        $otherBrand = Brand::query()->create([
            'account_id' => $otherAccount->id,
            'name' => 'Other Brand',
            'slug' => 'other-policy-brand',
        ]);

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach(Role::query()->where('name', 'owner')->firstOrFail(), ['account_id' => $account->id]);

        app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');
        app(SubscriptionService::class)->activatePlan($otherAccount, 'starter_monthly');

        return [$user, $account, $brand, $otherAccount, $otherBrand];
    }
}
