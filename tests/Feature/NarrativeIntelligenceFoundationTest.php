<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\DomainEvent;
use App\Models\Entity;
use App\Models\IntelligenceSignal;
use App\Models\Mention;
use App\Models\Narrative;
use App\Models\NarrativeGap;
use App\Models\Recommendation;
use App\Models\Role;
use App\Models\Topic;
use App\Models\User;
use App\Models\VisibilityProviderRun;
use App\Services\NarrativeIntelligenceService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Tests\TestCase;

class NarrativeIntelligenceFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_narrative_with_linked_context(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $topic = Topic::query()->create(['account_id' => $account->id, 'brand_id' => $brand->id, 'name' => 'AI Visibility', 'slug' => 'ai-visibility', 'status' => 'active']);
        $entity = Entity::query()->create(['account_id' => $account->id, 'brand_id' => $brand->id, 'name' => 'Argusly', 'entity_type' => 'company']);
        $mention = Mention::query()->create(['account_id' => $account->id, 'brand_id' => $brand->id, 'title' => 'Argusly is described as an SEO tool.', 'content' => 'Mention body.', 'sentiment' => 'neutral']);
        $competitor = Competitor::query()->create(['account_id' => $account->id, 'brand_id' => $brand->id, 'name' => 'CompeteCo', 'website' => 'https://compete.example', 'status' => 'active']);
        $run = VisibilityProviderRun::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'provider' => 'ChatGPT',
            'query' => 'What is Argusly?',
            'raw_response' => 'Argusly is an SEO tool.',
            'normalized_answer' => 'Argusly is an SEO tool.',
            'status' => 'completed',
        ]);

        $this->actingAs($user)
            ->post(route('app.narratives.store'), [
                'title' => 'AI Visibility Platform',
                'description' => 'Argusly should be understood as an AI visibility platform.',
                'narrative_type' => 'brand',
                'status' => 'active',
                'importance' => 'high',
                'topic_ids' => [$topic->id],
                'entity_ids' => [$entity->id],
                'mention_ids' => [$mention->id],
                'competitor_ids' => [$competitor->id],
                'visibility_provider_run_ids' => [$run->id],
            ])
            ->assertRedirect(route('app.narratives.index'));

        $narrative = Narrative::query()->where('account_id', $account->id)->firstOrFail();

        $this->assertSame('AI Visibility Platform', $narrative->title);
        $this->assertTrue($narrative->topics()->whereKey($topic)->exists());
        $this->assertTrue($narrative->entities()->whereKey($entity)->exists());
        $this->assertTrue($narrative->mentions()->whereKey($mention)->exists());
        $this->assertTrue($narrative->competitors()->whereKey($competitor)->exists());
        $this->assertTrue($narrative->visibilityProviderRuns()->whereKey($run)->exists());
        $this->assertDatabaseHas('domain_events', ['event_type' => 'NarrativeCreated', 'subject_id' => $narrative->id]);

        $this->actingAs($user)
            ->get(route('app.narratives.index'))
            ->assertOk()
            ->assertSee('Narrative intelligence')
            ->assertSee('AI Visibility Platform')
            ->assertSee('Narrative gap overview')
            ->assertSee('Narrative recommendations');
    }

    public function test_detecting_gap_creates_signal_domain_event_and_recommendations(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $narrative = Narrative::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Modern AI Software Company',
            'description' => 'The brand should be described as a modern AI software company.',
            'narrative_type' => 'brand',
            'status' => 'active',
            'importance' => 'critical',
        ]);

        $this->actingAs($user)
            ->post(route('app.narratives.gaps.store', $narrative), [
                'desired_state' => 'Modern AI Software Company',
                'detected_state' => 'Marketing Agency',
                'gap_score' => 72,
            ])
            ->assertRedirect(route('app.narratives.index'));

        $gap = NarrativeGap::query()->firstOrFail();
        $signal = IntelligenceSignal::query()->where('type', 'narrative_gap_detected')->firstOrFail();

        $this->assertSame($narrative->id, $gap->narrative_id);
        $this->assertSame('high', $signal->priority);
        $this->assertDatabaseHas('domain_events', ['event_type' => 'NarrativeGapDetected', 'subject_id' => $gap->id]);
        $this->assertDatabaseHas('recommendations', ['signal_id' => $signal->id, 'action_type' => 'create_content']);
        $this->assertDatabaseHas('recommendations', ['signal_id' => $signal->id, 'action_type' => 'refresh_positioning']);
        $this->assertDatabaseHas('recommendations', ['signal_id' => $signal->id, 'action_type' => 'launch_campaign']);
        $this->assertDatabaseHas('recommendations', ['signal_id' => $signal->id, 'action_type' => 'improve_citations']);

        $this->actingAs($user)
            ->get(route('app.narratives.index'))
            ->assertOk()
            ->assertSee('Modern AI Software Company')
            ->assertSee('Marketing Agency')
            ->assertSee('Create content to close narrative gap')
            ->assertSee('Improve citations');
    }

    public function test_observation_and_narrative_scope_are_tenant_safe(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $otherAccount = Account::query()->create(['name' => 'Other', 'slug' => 'other']);
        $otherBrand = Brand::query()->create(['account_id' => $otherAccount->id, 'name' => 'Other Brand', 'slug' => 'other-brand']);
        $visible = Narrative::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Visible Narrative',
            'description' => 'Visible.',
            'narrative_type' => 'product',
            'status' => 'active',
            'importance' => 'medium',
        ]);
        Narrative::query()->create([
            'account_id' => $otherAccount->id,
            'brand_id' => $otherBrand->id,
            'title' => 'Hidden Narrative',
            'description' => 'Hidden.',
            'narrative_type' => 'brand',
            'status' => 'active',
            'importance' => 'medium',
        ]);

        $this->actingAs($user)
            ->post(route('app.narratives.observations.store', $visible), [
                'observation' => 'Detected as AI visibility platform.',
                'sentiment' => 'positive',
                'confidence_score' => 91,
            ])
            ->assertRedirect(route('app.narratives.index'));

        $this->actingAs($user)
            ->get(route('app.narratives.index'))
            ->assertOk()
            ->assertSee('Visible Narrative')
            ->assertDontSee('Hidden Narrative');

        $this->assertTrue(Gate::forUser($user)->allows('view', $visible));
        $this->assertDatabaseHas('domain_events', ['event_type' => 'NarrativeObservationCaptured', 'subject_id' => $visible->id]);
    }

    public function test_cross_account_brand_is_rejected_by_service(): void
    {
        [, $account] = $this->tenantWithRole('owner');
        $otherAccount = Account::query()->create(['name' => 'Other', 'slug' => 'other']);
        $otherBrand = Brand::query()->create(['account_id' => $otherAccount->id, 'name' => 'Other Brand', 'slug' => 'other-brand']);

        $this->expectException(InvalidArgumentException::class);

        app(NarrativeIntelligenceService::class)->dashboardStats($account, $otherBrand);
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
