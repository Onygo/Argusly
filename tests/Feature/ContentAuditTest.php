<?php

namespace Tests\Feature;

use App\Jobs\RunContentAuditJob;
use App\Models\Account;
use App\Models\Brand;
use App\Models\ContentAsset;
use App\Models\ContentAudit;
use App\Models\Recommendation;
use App\Models\Role;
use App\Models\User;
use App\Services\ContentAuditService;
use App\Services\CreditService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContentAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_audit_action_creates_queued_audit_and_dispatches_job(): void
    {
        Queue::fake();

        [$editor, , $brand] = $this->tenantWithRole('editor');
        $asset = ContentAsset::factory()->forBrand($brand)->create(['title' => 'AI visibility guide']);

        $this->actingAs($editor)
            ->post(route('app.content.audit', $asset))
            ->assertRedirect(route('app.content.show', $asset));

        $audit = ContentAudit::query()->where('content_asset_id', $asset->id)->firstOrFail();

        $this->assertSame($asset->account_id, $audit->account_id);
        $this->assertSame($asset->brand_id, $audit->brand_id);
        $this->assertSame('queued', $audit->status);

        Queue::assertPushed(
            RunContentAuditJob::class,
            fn (RunContentAuditJob $job) => $job->contentAuditId === $audit->id,
        );
    }

    public function test_audit_job_stores_deterministic_scores_and_recommendations(): void
    {
        Queue::fake();

        [$editor, , $brand] = $this->tenantWithRole('editor');
        $asset = ContentAsset::factory()->forBrand($brand)->create([
            'title' => 'Short',
            'excerpt' => null,
            'body' => 'Tiny body without headings.',
            'metadata' => null,
            'seo_metadata' => null,
        ]);
        $audit = app(ContentAuditService::class)->requestForContentAsset($asset, $editor);

        (new RunContentAuditJob($audit->id))->handle(app(ContentAuditService::class));

        $audit->refresh();

        $this->assertSame('completed', $audit->status);
        $this->assertNotNull($audit->audited_at);
        $this->assertSame(53, $audit->score);
        $this->assertSame(60, $audit->seo_score);
        $this->assertSame(60, $audit->readability_score);
        $this->assertContains('Excerpt is missing.', $audit->issues);
        $this->assertContains('Add clear H2 or H3 sections so readers and AI systems can parse the structure.', $audit->recommendations);
        $this->assertDatabaseHas('evidence_items', [
            'account_id' => $audit->account_id,
            'brand_id' => $audit->brand_id,
            'subject_type' => $audit->getMorphClass(),
            'subject_id' => $audit->id,
            'evidence_type' => 'manual_note',
        ]);

        $recommendation = Recommendation::query()->where('account_id', $audit->account_id)->firstOrFail();
        $this->assertTrue($recommendation->evidenceItems()->exists());
    }

    public function test_latest_audit_and_history_are_tenant_and_brand_isolated(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('editor');
        $otherBrand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Other Brand', 'slug' => 'other-brand']);
        $user->brands()->attach($otherBrand, ['account_id' => $account->id, 'status' => 'active']);

        $visibleAsset = ContentAsset::factory()->forBrand($brand)->create(['title' => 'Visible content asset']);
        $hiddenAsset = ContentAsset::factory()->forBrand($otherBrand)->create(['title' => 'Hidden content asset']);

        ContentAudit::factory()->forContentAsset($visibleAsset)->create([
            'score' => 88,
            'summary' => 'Visible audit summary',
        ]);
        ContentAudit::factory()->forContentAsset($hiddenAsset)->create([
            'score' => 22,
            'summary' => 'Hidden audit summary',
        ]);

        $this->actingAs($user)
            ->get(route('app.content.show', $visibleAsset))
            ->assertOk()
            ->assertSee('Visible audit summary')
            ->assertSee('88/100')
            ->assertDontSee('Hidden audit summary');

        $this->actingAs($user)
            ->post(route('app.content.audit', $hiddenAsset))
            ->assertForbidden();
    }

    public function test_content_module_is_required_for_audit_action(): void
    {
        [$editor, , $brand] = $this->tenantWithRole('editor', activatePlan: false);
        $asset = ContentAsset::factory()->forBrand($brand)->create();

        $this->actingAs($editor)
            ->post(route('app.content.audit', $asset))
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Account, 2: Brand}
     */
    private function tenantWithRole(string $roleName, bool $activatePlan = true): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => fake()->company(), 'slug' => fake()->unique()->slug()]);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Alpha Brand', 'slug' => fake()->unique()->slug()]);
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);

        if ($activatePlan) {
            app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');
            app(CreditService::class)->grant($account, 1000, $user, 'Test credits');
        }

        return [$user, $account, $brand];
    }
}
