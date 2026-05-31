<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AnswerBlock;
use App\Models\Brand;
use App\Models\ContentAsset;
use App\Models\Role;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnswerBlockTest extends TestCase
{
    use RefreshDatabase;

    public function test_answer_blocks_index_is_tenant_and_brand_scoped(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('editor');
        $otherBrand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Other Brand', 'slug' => 'other-brand']);
        $otherAccount = Account::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $externalBrand = Brand::query()->create(['account_id' => $otherAccount->id, 'name' => 'External Brand', 'slug' => 'external-brand']);

        AnswerBlock::factory()->forBrand($brand)->create(['question' => 'Visible question?']);
        AnswerBlock::factory()->forBrand($otherBrand)->create(['question' => 'Hidden brand question?']);
        AnswerBlock::factory()->forBrand($externalBrand)->create(['question' => 'Hidden tenant question?']);

        $this->actingAs($user)
            ->get(route('app.content.answer-blocks.index'))
            ->assertOk()
            ->assertSee('Visible question?')
            ->assertDontSee('Hidden brand question?')
            ->assertDontSee('Hidden tenant question?');
    }

    public function test_editor_can_create_standalone_answer_block(): void
    {
        [$editor, , $brand] = $this->tenantWithRole('editor');

        $this->actingAs($editor)
            ->post(route('app.content.answer-blocks.store'), $this->answerBlockPayload([
                'question' => 'What is Argusly visibility?',
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('answer_blocks', [
            'brand_id' => $brand->id,
            'content_asset_id' => null,
            'question' => 'What is Argusly visibility?',
            'status' => 'draft',
        ]);
    }

    public function test_editor_can_create_answer_block_from_content_asset(): void
    {
        [$editor, , $brand] = $this->tenantWithRole('editor');
        $asset = ContentAsset::factory()->forBrand($brand)->create(['title' => 'AI visibility guide']);

        $this->actingAs($editor)
            ->get(route('app.content.answer-blocks.create-for-asset', $asset))
            ->assertOk()
            ->assertSee('AI visibility guide');

        $this->actingAs($editor)
            ->post(route('app.content.answer-blocks.store-for-asset', $asset), $this->answerBlockPayload([
                'question' => 'How does this guide help?',
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('answer_blocks', [
            'brand_id' => $brand->id,
            'content_asset_id' => $asset->id,
            'question' => 'How does this guide help?',
        ]);

        $this->actingAs($editor)
            ->get(route('app.content.show', $asset))
            ->assertOk()
            ->assertSee('How does this guide help?');
    }

    public function test_viewer_cannot_create_or_edit_answer_blocks(): void
    {
        [$viewer, , $brand] = $this->tenantWithRole('viewer');
        $answerBlock = AnswerBlock::factory()->forBrand($brand)->create(['status' => 'draft']);

        $this->actingAs($viewer)
            ->get(route('app.content.answer-blocks.create'))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->get(route('app.content.answer-blocks.edit', $answerBlock))
            ->assertForbidden();
    }

    public function test_editor_can_update_and_archive_answer_blocks(): void
    {
        [$editor, , $brand] = $this->tenantWithRole('editor');
        $answerBlock = AnswerBlock::factory()->forBrand($brand)->create(['status' => 'draft']);

        $this->actingAs($editor)
            ->put(route('app.content.answer-blocks.update', $answerBlock), $this->answerBlockPayload([
                'question' => 'Updated answer question?',
            ]))
            ->assertRedirect(route('app.content.answer-blocks.show', $answerBlock));

        $this->assertDatabaseHas('answer_blocks', [
            'id' => $answerBlock->id,
            'question' => 'Updated answer question?',
        ]);

        $this->actingAs($editor)
            ->delete(route('app.content.answer-blocks.destroy', $answerBlock))
            ->assertRedirect(route('app.content.answer-blocks.index'));

        $this->assertDatabaseHas('answer_blocks', [
            'id' => $answerBlock->id,
            'status' => 'archived',
        ]);
    }

    public function test_publisher_can_create_published_answer_block_but_editor_cannot(): void
    {
        [$editor] = $this->tenantWithRole('editor');

        $this->actingAs($editor)
            ->post(route('app.content.answer-blocks.store'), $this->answerBlockPayload(['status' => 'published']))
            ->assertForbidden();

        [$publisher, , $brand] = $this->tenantWithRole('publisher', slug: 'publisher-account');

        $this->actingAs($publisher)
            ->post(route('app.content.answer-blocks.store'), $this->answerBlockPayload([
                'status' => 'published',
                'question' => 'Can this block publish?',
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('answer_blocks', [
            'brand_id' => $brand->id,
            'question' => 'Can this block publish?',
            'status' => 'published',
        ]);
    }

    public function test_content_module_is_required_for_answer_blocks(): void
    {
        [$editor] = $this->tenantWithRole('editor', activatePlan: false);

        $this->actingAs($editor)
            ->get(route('app.content.answer-blocks.index'))
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Account, 2: Brand}
     */
    private function tenantWithRole(string $roleName, bool $activatePlan = true, string $slug = 'alpha-account'): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => str($slug)->replace('-', ' ')->headline(), 'slug' => $slug]);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => str($slug)->headline().' Brand', 'slug' => $slug.'-brand']);
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);

        if ($activatePlan) {
            app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');
        }

        return [$user, $account, $brand];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function answerBlockPayload(array $overrides = []): array
    {
        return [
            'question' => 'What is an Answer Block?',
            'answer' => 'An Answer Block is a reusable answer-ready content unit for future AI visibility workflows.',
            'type' => 'direct_answer',
            'status' => 'draft',
            'language' => 'en',
            'position' => 1,
            ...$overrides,
        ];
    }
}
