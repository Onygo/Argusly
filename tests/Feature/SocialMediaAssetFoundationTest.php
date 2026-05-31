<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Integration;
use App\Models\IntegrationConnection;
use App\Models\SocialMediaAsset;
use App\Models\SocialPost;
use App\Models\SocialProfile;
use App\Models\User;
use Database\Seeders\IntegrationCatalogSeeder;
use Database\Seeders\LanguageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class SocialMediaAssetFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_social_media_asset_can_reference_social_post(): void
    {
        [$account, $brand, $post] = $this->context();

        $asset = SocialMediaAsset::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'social_post_id' => $post->id,
            'provider' => 'linkedin',
            'type' => 'image',
            'status' => 'draft',
            'file_path' => 'social/linkedin/image.png',
            'mime_type' => 'image/png',
            'size_bytes' => 2048,
            'metadata' => ['alt' => 'Example'],
        ]);
        $post->update(['media' => [['social_media_asset_id' => $asset->id]]]);

        $this->assertNotEmpty($asset->uuid);
        $this->assertSame($post->id, $asset->socialPost->id);
        $this->assertSame($asset->id, $post->fresh()->media[0]['social_media_asset_id']);
    }

    public function test_social_media_asset_rejects_cross_account_brand(): void
    {
        [$account] = $this->context();
        $otherAccount = Account::query()->create(['name' => 'Other', 'slug' => fake()->unique()->slug()]);
        $otherBrand = Brand::query()->create(['account_id' => $otherAccount->id, 'name' => 'Other Brand', 'slug' => fake()->unique()->slug()]);

        $this->expectException(InvalidArgumentException::class);

        SocialMediaAsset::query()->create([
            'account_id' => $account->id,
            'brand_id' => $otherBrand->id,
            'provider' => 'linkedin',
            'type' => 'image',
            'status' => 'draft',
        ]);
    }

    /**
     * @return array{0: Account, 1: Brand, 2: SocialPost}
     */
    private function context(): array
    {
        $this->seed(LanguageSeeder::class);
        $this->seed(IntegrationCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Account', 'slug' => fake()->unique()->slug()]);
        $brand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => 'Brand',
            'slug' => fake()->unique()->slug(),
            'enabled_content_languages' => ['en'],
            'default_content_language' => 'en',
        ]);
        $profile = SocialProfile::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'integration_connection_id' => IntegrationConnection::query()->create([
                'integration_id' => Integration::query()->where('key', 'linkedin')->firstOrFail()->id,
                'owner_user_id' => $user->id,
                'account_id' => $account->id,
                'brand_id' => $brand->id,
                'name' => 'LinkedIn',
                'status' => 'active',
            ])->id,
            'owner_user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_profile_id' => 'person-1',
            'display_name' => 'LinkedIn',
            'type' => 'person',
            'status' => 'connected',
        ]);
        $post = SocialPost::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'social_profile_id' => $profile->id,
            'provider' => 'linkedin',
            'status' => 'draft',
            'post_text' => 'Post',
            'language' => 'en',
            'created_by' => $user->id,
        ]);

        return [$account, $brand, $post];
    }
}
