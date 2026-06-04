<?php

namespace Tests\Feature;

use App\Jobs\RunContentAuditJob;
use App\Jobs\RunVisibilityCheckJob;
use App\Models\Account;
use App\Models\AnswerBlock;
use App\Models\Brand;
use App\Models\ContentAsset;
use App\Models\ContentAudit;
use App\Models\Recommendation;
use App\Models\Role;
use App\Models\User;
use App\Models\VisibilityCheck;
use App\Services\CreditService;
use App\Services\RecommendationActionService;
use App\Services\RecommendationEngineService;
use App\Services\SignalManager;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

class RecommendationEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_engine_converts_signals_into_recommendations_and_deduplicates(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');

        $signal = app(SignalManager::class)->record($account, [
            'source' => 'content_lifecycle',
            'type' => 'content_opportunity',
            'category' => 'content',
            'priority' => 'high',
            'dedupe_key' => 'lifecycle:test',
            'title' => 'Refresh recommended: Old article',
            'summary' => 'The article has degraded.',
            'recommended_action' => 'Refresh the article.',
            'impact_score' => 82,
            'confidence_score' => 90,
            'payload' => ['content_asset_id' => 123, 'health_score' => 42],
        ], $brand);

        $this->assertDatabaseHas('recommendations', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'signal_id' => $signal->id,
            'title' => 'Refresh article',
            'status' => 'new',
        ]);
        $this->assertDatabaseHas('recommendations', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'signal_id' => $signal->id,
            'title' => 'Run content audit',
        ]);

        app(RecommendationEngineService::class)->generateForSignal($signal->refresh());

        $this->assertSame(2, Recommendation::query()->where('signal_id', $signal->id)->count());

        $this->actingAs($user)
            ->get(route('app.intelligence'))
            ->assertOk()
            ->assertSee('Refresh article')
            ->assertSee('Run content audit');
    }

    public function test_dashboard_shows_tenant_brand_scoped_recommendations(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $otherBrand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Other Brand', 'slug' => 'other-brand']);

        app(SignalManager::class)->record($account, [
            'source' => 'content_audit',
            'type' => 'content_audit_completed',
            'category' => 'visibility',
            'priority' => 'medium',
            'dedupe_key' => 'audit:visible',
            'title' => 'Audit completed',
            'summary' => 'Audit completed.',
            'impact_score' => 61,
            'confidence_score' => 88,
            'payload' => ['content_asset_id' => 1, 'score' => 55],
        ], $brand);

        app(SignalManager::class)->record($account, [
            'source' => 'content_audit',
            'type' => 'content_audit_completed',
            'category' => 'visibility',
            'priority' => 'medium',
            'dedupe_key' => 'audit:hidden',
            'title' => 'Hidden audit completed',
            'summary' => 'Hidden audit completed.',
            'impact_score' => 61,
            'confidence_score' => 88,
            'payload' => ['content_asset_id' => 2, 'score' => 55],
        ], $otherBrand);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Recommendations')
            ->assertSee('Create FAQ')
            ->assertDontSee('Hidden audit completed');
    }

    public function test_accept_and_dismiss_are_tenant_safe(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $otherAccount = Account::query()->create(['name' => 'Beta', 'slug' => 'beta']);

        $signal = app(SignalManager::class)->record($account, [
            'source' => 'visibility',
            'type' => 'visibility_change',
            'category' => 'visibility',
            'priority' => 'high',
            'dedupe_key' => 'visibility:test',
            'title' => 'Visibility changed',
            'summary' => 'Visibility moved.',
            'impact_score' => 70,
            'confidence_score' => 82,
        ], $brand);
        $recommendation = Recommendation::query()->where('signal_id', $signal->id)->firstOrFail();

        $hiddenRecommendation = Recommendation::query()->create([
            'account_id' => $otherAccount->id,
            'title' => 'Hidden recommendation',
            'summary' => 'Hidden.',
            'recommended_action' => 'Do not expose.',
            'impact_score' => 10,
            'confidence_score' => 10,
            'status' => 'new',
        ]);

        $this->actingAs($user)
            ->post(route('app.recommendations.accept', $recommendation))
            ->assertRedirect();

        $this->assertSame('accepted', $recommendation->refresh()->status);
        $this->assertSame($user->id, $recommendation->accepted_by);
        $this->assertNotNull($recommendation->accepted_at);

        $this->actingAs($user)
            ->post(route('app.recommendations.dismiss', $recommendation))
            ->assertRedirect();

        $this->assertSame('dismissed', $recommendation->refresh()->status);

        $this->actingAs($user)
            ->post(route('app.recommendations.accept', $hiddenRecommendation))
            ->assertNotFound();
    }

    public function test_executable_recommendation_accepts_and_queues_content_audit_action(): void
    {
        Queue::fake();

        [$user, $account, $brand] = $this->tenantWithRole('owner');
        app(CreditService::class)->grant($account, 100, $user, 'Test credits');
        $asset = ContentAsset::factory()->forBrand($brand)->create(['title' => 'Actionable guide']);
        $recommendation = Recommendation::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Run content audit',
            'summary' => 'Audit the content.',
            'recommended_action' => 'Run a fresh content audit.',
            'action_type' => 'run_content_audit',
            'action_payload' => ['content_asset_id' => $asset->id],
            'status' => 'new',
        ]);

        $this->actingAs($user)
            ->post(route('app.recommendations.execute', $recommendation))
            ->assertRedirect();

        $recommendation->refresh();
        $audit = ContentAudit::query()->where('content_asset_id', $asset->id)->firstOrFail();

        $this->assertSame('accepted', $recommendation->status);
        $this->assertSame('queued', $recommendation->execution_status);
        $this->assertSame($user->id, $recommendation->accepted_by);
        $this->assertNotNull($recommendation->executed_at);
        $this->assertSame('queued', $audit->status);

        Queue::assertPushed(RunContentAuditJob::class, fn (RunContentAuditJob $job) => $job->contentAuditId === $audit->id);
        $this->assertDatabaseHas('domain_events', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'event_type' => 'RecommendationActionExecuted',
            'subject_id' => $recommendation->id,
        ]);
    }

    public function test_executable_recommendation_can_create_answer_block_immediately(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $asset = ContentAsset::factory()->forBrand($brand)->create([
            'title' => 'Answer source',
            'excerpt' => 'A concise answer can be extracted from this source.',
        ]);
        $recommendation = Recommendation::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Create Answer Block',
            'summary' => 'Create a reusable answer.',
            'recommended_action' => 'Create an answer block.',
            'action_type' => 'create_answer_block',
            'action_payload' => [
                'content_asset_id' => $asset->id,
                'question' => 'What is this source about?',
            ],
            'status' => 'new',
        ]);

        app(RecommendationActionService::class)->execute($recommendation, $user);

        $recommendation->refresh();
        $answer = AnswerBlock::query()->where('content_asset_id', $asset->id)->firstOrFail();

        $this->assertSame('completed', $recommendation->status);
        $this->assertSame('completed', $recommendation->execution_status);
        $this->assertSame('What is this source about?', $answer->question);
        $this->assertSame('draft', $answer->status);
    }

    public function test_visibility_action_queues_visibility_check(): void
    {
        Queue::fake();

        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $recommendation = Recommendation::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Improve AI visibility',
            'summary' => 'Run a visibility check.',
            'recommended_action' => 'Run a visibility check.',
            'action_type' => 'run_visibility_check',
            'action_payload' => [
                'provider' => 'ChatGPT',
                'query' => 'best answer intelligence platform',
            ],
            'status' => 'new',
        ]);

        app(RecommendationActionService::class)->execute($recommendation, $user);

        $check = VisibilityCheck::query()->where('query', 'best answer intelligence platform')->firstOrFail();

        $this->assertSame('queued', $recommendation->refresh()->execution_status);
        $this->assertSame('ChatGPT', $check->provider);
        Queue::assertPushed(RunVisibilityCheckJob::class, fn (RunVisibilityCheckJob $job) => $job->visibilityCheckId === $check->id);
    }

    public function test_recommendation_actions_reject_cross_tenant_payloads(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        [, , $otherBrand] = $this->tenantWithRole('owner');
        $otherAsset = ContentAsset::factory()->forBrand($otherBrand)->create();
        $recommendation = Recommendation::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Create Answer Block',
            'summary' => 'Create a reusable answer.',
            'recommended_action' => 'Create an answer block.',
            'action_type' => 'create_answer_block',
            'action_payload' => ['content_asset_id' => $otherAsset->id],
            'status' => 'new',
        ]);

        try {
            app(RecommendationActionService::class)->execute($recommendation, $user);
            $this->fail('Expected cross-tenant recommendation action to fail.');
        } catch (InvalidArgumentException) {
            $this->assertSame('failed', $recommendation->refresh()->execution_status);
        }
        $this->assertSame(0, AnswerBlock::query()->where('content_asset_id', $otherAsset->id)->count());
    }

    /**
     * @return array{0: User, 1: Account, 2: Brand}
     */
    private function tenantWithRole(string $roleName): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Alpha', 'slug' => fake()->unique()->slug()]);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Alpha Brand', 'slug' => fake()->unique()->slug()]);
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);
        app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');
        app(CreditService::class)->grant($account, 5000, $user, 'Test LLM credits');

        return [$user, $account, $brand];
    }
}
