<?php

namespace Tests\Feature;

use App\Jobs\RunVisibilityCheckJob;
use App\Models\Account;
use App\Models\Brand;
use App\Models\Role;
use App\Models\User;
use App\Models\VisibilityAnswerEntity;
use App\Models\VisibilityCheck;
use App\Models\VisibilityCitation;
use App\Models\VisibilityPromptTemplate;
use App\Models\VisibilityProviderRun;
use App\Models\VisibilityResult;
use App\Services\Subscriptions\SubscriptionService;
use App\Services\VisibilityMonitoringService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

class VisibilityMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_visibility_check_and_queue_placeholder_job(): void
    {
        Queue::fake();

        [$user, $account, $brand] = $this->tenantWithRole('owner');

        $this->actingAs($user)
            ->post(route('app.visibility.checks.store'), [
                'provider' => 'ChatGPT',
                'brand' => 'Argusly',
                'query' => 'best AI visibility platform for content teams',
            ])
            ->assertRedirect(route('app.visibility'));

        $check = VisibilityCheck::query()->firstOrFail();

        $this->assertSame($account->id, $check->account_id);
        $this->assertSame($brand->id, $check->brand_id);
        $this->assertSame('ChatGPT', $check->provider);
        $this->assertSame('Argusly', $check->brand);

        Queue::assertPushed(
            RunVisibilityCheckJob::class,
            fn (RunVisibilityCheckJob $job) => $job->visibilityCheckId === $check->id,
        );
    }

    public function test_placeholder_job_creates_result_and_snapshot(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $service = app(VisibilityMonitoringService::class);
        $check = $service->createCheck($account, $brand, [
            'provider' => 'Perplexity',
            'brand' => 'Argusly',
            'query' => 'who helps brands monitor AI answer visibility',
        ]);

        (new RunVisibilityCheckJob($check->id))->handle($service);

        $result = VisibilityResult::query()->where('visibility_check_id', $check->id)->firstOrFail();

        $this->assertSame('Perplexity', $result->provider);
        $this->assertSame('en', $result->language);
        $this->assertSame('en_US', $result->locale);
        $this->assertNotNull($result->score);
        $this->assertTrue($result->metadata['placeholder']);
        $this->assertDatabaseHas('evidence_items', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'subject_type' => $result->getMorphClass(),
            'subject_id' => $result->id,
            'evidence_type' => 'ai_answer',
        ]);
        $this->assertDatabaseHas('visibility_snapshots', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'provider' => 'Perplexity',
            'results_count' => 1,
        ]);

        $run = VisibilityProviderRun::query()->where('visibility_check_id', $check->id)->firstOrFail();
        $this->assertSame('Perplexity', $run->provider);
        $this->assertSame('en', $run->language);
        $this->assertSame('en_US', $run->locale);
        $this->assertSame('completed', $run->status);
        $this->assertSame(0, $run->cost_credits);
        $this->assertTrue($run->metadata['placeholder']);
        $this->assertDatabaseHas('visibility_citations', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'provider_run_id' => $run->id,
            'rank' => $result->position ?? 1,
        ]);
        $this->assertDatabaseHas('visibility_answer_entities', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'provider_run_id' => $run->id,
            'entity_name' => 'Argusly',
            'entity_type' => 'brand',
        ]);
        $this->assertDatabaseHas('evidence_items', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'subject_type' => $run->getMorphClass(),
            'subject_id' => $run->id,
            'evidence_type' => 'provider_payload',
        ]);

        $this->actingAs($user)
            ->get(route('app.visibility'))
            ->assertOk()
            ->assertSee('Visibility timeline')
            ->assertSee('Perplexity')
            ->assertSee('Provider runs')
            ->assertSee('Prompt library')
            ->assertSee('Prompt, run, citation and entity schema ready');
    }

    public function test_prompt_templates_and_provider_artifacts_are_tenant_safe(): void
    {
        [, $account, $brand] = $this->tenantWithRole('owner');
        $otherAccount = Account::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $otherBrand = Brand::query()->create(['account_id' => $otherAccount->id, 'name' => 'Other Brand', 'slug' => 'other-brand']);
        $service = app(VisibilityMonitoringService::class);

        $template = $service->createPromptTemplate($account, $brand, [
            'name' => 'Buying intent',
            'prompt' => 'Answer as a B2B buyer researching AI visibility vendors.',
            'language' => 'en',
            'intent' => 'commercial',
            'locale' => 'en_US',
            'market' => 'US',
            'persona' => 'marketing leader',
        ]);

        $this->assertSame($account->id, $template->account_id);
        $this->assertSame($brand->id, $template->brand_id);
        $this->assertSame('en', $template->language);
        $this->assertSame('active', $template->status);

        $this->expectException(InvalidArgumentException::class);

        VisibilityProviderRun::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'provider' => 'ChatGPT',
            'prompt_template_id' => VisibilityPromptTemplate::query()->create([
                'account_id' => $otherAccount->id,
                'brand_id' => $otherBrand->id,
                'name' => 'Foreign template',
                'prompt' => 'Foreign prompt',
                'status' => 'active',
            ])->id,
            'query' => 'tenant safe visibility query',
            'status' => 'completed',
            'captured_at' => now(),
        ]);
    }

    public function test_prompt_library_crud_duplicate_archive_and_fake_run(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');

        $this->actingAs($user)
            ->post(route('app.visibility.prompts.store'), [
                'brand_id' => $brand->id,
                'name' => 'Competitor prompt',
                'prompt' => 'Who are the leading competitors of {brand}?',
                'language' => 'en',
                'intent' => 'competitive',
                'locale' => 'en_US',
                'market' => 'US',
            ])
            ->assertRedirect(route('app.visibility'));

        $template = VisibilityPromptTemplate::query()->firstOrFail();

        $this->assertSame($account->id, $template->account_id);
        $this->assertSame($brand->id, $template->brand_id);
        $this->assertSame('active', $template->status);

        $this->actingAs($user)
            ->put(route('app.visibility.prompts.update', $template), [
                'brand_id' => $brand->id,
                'name' => 'Updated competitor prompt',
                'prompt' => 'What does {brand} do?',
                'language' => 'nl',
                'intent' => 'brand',
                'locale' => 'nl_NL',
                'market' => 'NL',
                'status' => 'active',
            ])
            ->assertRedirect(route('app.visibility'));

        $this->assertSame('Updated competitor prompt', $template->refresh()->name);
        $this->assertSame('nl', $template->language);
        $this->assertSame('nl_NL', $template->locale);

        $this->actingAs($user)
            ->post(route('app.visibility.prompts.duplicate', $template))
            ->assertRedirect(route('app.visibility'));

        $this->assertSame(2, VisibilityPromptTemplate::query()->where('brand_id', $brand->id)->count());

        $this->actingAs($user)
            ->post(route('app.visibility.prompts.run', $template), ['provider' => 'chatgpt'])
            ->assertRedirect(route('app.visibility'));

        $run = VisibilityProviderRun::query()->where('prompt_template_id', $template->id)->firstOrFail();

        $this->assertSame('ChatGPT', $run->provider);
        $this->assertSame('completed', $run->status);
        $this->assertSame('nl', $run->language);
        $this->assertSame('NL', $run->market);
        $this->assertSame('nl', $run->input_language);
        $this->assertSame('nl', $run->normalized_answer_language);
        $this->assertSame('nl', $run->detected_language);
        $this->assertStringContainsString($brand->name, $run->query);
        $this->assertDatabaseHas('visibility_citations', [
            'provider_run_id' => $run->id,
            'account_id' => $account->id,
            'brand_id' => $brand->id,
        ]);
        $this->assertDatabaseHas('visibility_answer_entities', [
            'provider_run_id' => $run->id,
            'entity_name' => $brand->name,
        ]);
        $this->assertDatabaseHas('domain_events', [
            'event_type' => 'VisibilityProviderRunCompleted',
            'subject_id' => $run->id,
        ]);

        $this->actingAs($user)
            ->post(route('app.visibility.prompts.archive', $template))
            ->assertRedirect(route('app.visibility'));

        $this->assertSame('archived', $template->refresh()->status);

        $this->actingAs($user)
            ->get(route('app.visibility'))
            ->assertOk()
            ->assertSee('Prompt library')
            ->assertSee('Updated competitor prompt')
            ->assertSee('NL market, Dutch language')
            ->assertSee('Run test');
    }

    public function test_visibility_dashboard_filters_runs_by_language_and_market(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');

        VisibilityProviderRun::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'provider' => 'ChatGPT',
            'query' => 'Dutch market prompt',
            'language' => 'nl',
            'locale' => 'nl_NL',
            'market' => 'NL',
            'input_language' => 'nl',
            'normalized_answer_language' => 'nl',
            'normalized_answer' => 'Dutch answer',
            'status' => 'completed',
            'captured_at' => now(),
            'metadata' => ['visibility_score' => 82],
        ]);
        VisibilityProviderRun::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'provider' => 'ChatGPT',
            'query' => 'English market prompt',
            'language' => 'en',
            'locale' => 'en_US',
            'market' => 'US',
            'input_language' => 'en',
            'normalized_answer_language' => 'en',
            'normalized_answer' => 'English answer',
            'status' => 'completed',
            'captured_at' => now()->subMinute(),
            'metadata' => ['visibility_score' => 74],
        ]);

        $this->actingAs($user)
            ->get(route('app.visibility', ['language' => 'nl', 'market' => 'NL']))
            ->assertOk()
            ->assertSee('Dutch market prompt')
            ->assertSee('NL latest')
            ->assertDontSee('English market prompt');
    }

    public function test_prompt_library_actions_are_current_brand_safe(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $otherBrand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Other Brand', 'slug' => 'other-brand']);
        $user->brands()->attach($otherBrand, ['account_id' => $account->id, 'status' => 'active']);
        $foreignTemplate = VisibilityPromptTemplate::query()->create([
            'account_id' => $account->id,
            'brand_id' => $otherBrand->id,
            'name' => 'Other prompt',
            'prompt' => 'What does {brand} do?',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->post(route('app.visibility.prompts.run', $foreignTemplate), ['provider' => 'chatgpt'])
            ->assertNotFound();

        $this->assertDatabaseMissing('visibility_provider_runs', [
            'prompt_template_id' => $foreignTemplate->id,
            'brand_id' => $brand->id,
        ]);
    }

    public function test_provider_citations_and_entities_reject_cross_tenant_runs(): void
    {
        [, $account, $brand] = $this->tenantWithRole('owner');
        $otherAccount = Account::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $otherBrand = Brand::query()->create(['account_id' => $otherAccount->id, 'name' => 'Other Brand', 'slug' => 'other-brand']);

        $run = VisibilityProviderRun::query()->create([
            'account_id' => $otherAccount->id,
            'brand_id' => $otherBrand->id,
            'provider' => 'Claude',
            'query' => 'foreign run',
            'status' => 'completed',
            'captured_at' => now(),
        ]);

        try {
            VisibilityCitation::query()->create([
                'account_id' => $account->id,
                'brand_id' => $brand->id,
                'provider_run_id' => $run->id,
                'url' => 'https://example.com',
            ]);
            $this->fail('Expected cross-tenant citation to be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseMissing('visibility_citations', [
                'account_id' => $account->id,
                'brand_id' => $brand->id,
                'provider_run_id' => $run->id,
            ]);
        }

        $this->expectException(InvalidArgumentException::class);

        VisibilityAnswerEntity::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'provider_run_id' => $run->id,
            'entity_name' => 'Argusly',
        ]);
    }

    public function test_visibility_timeline_is_tenant_and_brand_scoped(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $otherBrand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Other Brand', 'slug' => 'other-brand']);
        $otherAccount = Account::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $thirdBrand = Brand::query()->create(['account_id' => $otherAccount->id, 'name' => 'Third Brand', 'slug' => 'third-brand']);

        VisibilityCheck::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'provider' => 'Google',
            'brand' => 'Visible Brand',
            'query' => 'visible query',
            'status' => 'active',
        ]);
        VisibilityCheck::query()->create([
            'account_id' => $account->id,
            'brand_id' => $otherBrand->id,
            'provider' => 'Claude',
            'brand' => 'Hidden Same Account',
            'query' => 'hidden same account query',
            'status' => 'active',
        ]);
        VisibilityCheck::query()->create([
            'account_id' => $otherAccount->id,
            'brand_id' => $thirdBrand->id,
            'provider' => 'Gemini',
            'brand' => 'Hidden Other Account',
            'query' => 'hidden other account query',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('app.visibility'))
            ->assertOk()
            ->assertSee('Visible Brand')
            ->assertDontSee('Hidden Same Account')
            ->assertDontSee('Hidden Other Account');
    }

    public function test_dashboard_shows_visibility_widgets(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $service = app(VisibilityMonitoringService::class);
        $check = $service->createCheck($account, $brand, [
            'provider' => 'Google AI Overviews',
            'brand' => 'Argusly',
            'query' => 'AI answer visibility monitoring',
        ]);
        $service->runPlaceholderCheck($check);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Visibility monitoring')
            ->assertSee('Open visibility timeline')
            ->assertSee('Latest score');
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
