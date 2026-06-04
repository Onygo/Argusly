<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\VisibilityPromptTemplate;
use App\Models\VisibilityProviderRun;
use App\Models\VisibilityRunSchedule;
use App\Services\CreditService;
use Database\Seeders\CreditCostCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class VisibilityRunSchedulerTest extends TestCase
{
    use RefreshDatabase;

    public function test_visibility_run_due_command_runs_due_schedule_and_stores_artifacts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-29 10:00:00'));

        [$account, $brand, $template] = $this->tenantWithTemplate();
        app(CreditService::class)->grant($account, 20, null, 'Test credits');

        $schedule = VisibilityRunSchedule::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'prompt_template_id' => $template->id,
            'provider' => 'chatgpt',
            'language' => 'nl',
            'locale' => 'nl_NL',
            'market' => 'NL',
            'persona' => 'CMO',
            'intent' => 'competitive',
            'frequency' => 'daily',
            'status' => 'active',
            'next_run_at' => now()->subMinute(),
            'settings' => ['source' => 'test'],
        ]);

        $this->artisan('visibility:run-due')
            ->expectsOutput('Processed 1 due AI visibility run schedule(s).')
            ->assertExitCode(0);

        $run = VisibilityProviderRun::query()->firstOrFail();

        $this->assertSame('completed', $run->status);
        $this->assertSame('ChatGPT', $run->provider);
        $this->assertSame($template->id, $run->prompt_template_id);
        $this->assertSame('nl', $run->language);
        $this->assertSame('nl_NL', $run->locale);
        $this->assertSame('NL', $run->market);
        $this->assertSame('nl', $run->input_language);
        $this->assertSame('NL', $run->target_market);
        $this->assertSame('nl', $run->normalized_answer_language);
        $this->assertSame('nl', $run->detected_language);
        $this->assertSame('CMO', $run->persona);
        $this->assertSame(15, $run->cost_credits);
        $this->assertStringContainsString('Argusly', $run->normalized_answer);
        $this->assertSame($schedule->id, $run->metadata['visibility_run_schedule_id']);
        $this->assertGreaterThan(0, $run->citations()->count());
        $this->assertGreaterThan(0, $run->answerEntities()->count());

        $schedule->refresh();

        $this->assertNotNull($schedule->last_run_at);
        $this->assertTrue($schedule->next_run_at->equalTo(now()->addDay()));
        $this->assertSame(5, app(CreditService::class)->balance($account));
        $this->assertSame('nl', $schedule->language);
        $this->assertSame('NL', $schedule->market);

        $this->assertDatabaseHas('credit_transactions', [
            'account_id' => $account->id,
            'user_id' => null,
            'amount' => -15,
            'type' => 'visibility_check',
        ]);
        $this->assertDatabaseHas('domain_events', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'event_type' => 'VisibilityProviderRunCompleted',
            'subject_id' => $run->id,
        ]);
        $this->assertDatabaseHas('domain_events', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'event_type' => 'VisibilityRunScheduleExecuted',
            'subject_id' => $schedule->id,
        ]);
        $this->assertDatabaseHas('intelligence_signals', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'source' => 'domain_event',
            'type' => 'visibility_change',
        ]);
    }

    public function test_manual_schedules_are_not_run_by_due_command(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-29 10:00:00'));

        [$account, $brand, $template] = $this->tenantWithTemplate();
        app(CreditService::class)->grant($account, 20, null, 'Test credits');

        VisibilityRunSchedule::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'prompt_template_id' => $template->id,
            'provider' => 'claude',
            'frequency' => 'manual',
            'status' => 'active',
            'next_run_at' => now()->subMinute(),
        ]);

        $this->artisan('visibility:run-due')
            ->expectsOutput('Processed 0 due AI visibility run schedule(s).')
            ->assertExitCode(0);

        $this->assertDatabaseCount('visibility_provider_runs', 0);
        $this->assertSame(20, app(CreditService::class)->balance($account));
    }

    public function test_visibility_run_schedule_enforces_prompt_template_tenant(): void
    {
        [$account, $brand] = $this->tenant();
        $otherBrand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => 'Other Brand',
            'slug' => fake()->unique()->slug(),
        ]);
        $foreignTemplate = VisibilityPromptTemplate::query()->create([
            'account_id' => $account->id,
            'brand_id' => $otherBrand->id,
            'name' => 'Foreign template',
            'prompt' => 'What does {brand} do?',
            'intent' => 'research',
            'locale' => 'en_US',
            'market' => 'US',
            'status' => 'active',
        ]);

        $this->expectException(InvalidArgumentException::class);

        VisibilityRunSchedule::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'prompt_template_id' => $foreignTemplate->id,
            'provider' => 'gemini',
            'frequency' => 'weekly',
            'status' => 'active',
            'next_run_at' => now(),
        ]);
    }

    /**
     * @return array{0: Account, 1: Brand}
     */
    private function tenant(): array
    {
        $this->seed(CreditCostCatalogSeeder::class);

        $account = Account::query()->create(['name' => 'Visibility Account', 'slug' => fake()->unique()->slug()]);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Argusly', 'slug' => fake()->unique()->slug()]);

        return [$account, $brand];
    }

    /**
     * @return array{0: Account, 1: Brand, 2: VisibilityPromptTemplate}
     */
    private function tenantWithTemplate(): array
    {
        [$account, $brand] = $this->tenant();
        $template = VisibilityPromptTemplate::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Leading competitors',
            'prompt' => 'Who are the leading competitors of {brand}?',
            'language' => 'en',
            'intent' => 'competitive',
            'locale' => 'en_US',
            'market' => 'US',
            'status' => 'active',
        ]);

        return [$account, $brand, $template];
    }
}
