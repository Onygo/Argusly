<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Brand;
use App\Models\ContentAsset;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ContentAsset>
 */
class ContentAssetFactory extends Factory
{
    protected $model = ContentAsset::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $account = Account::query()->create([
            'name' => fake()->company(),
            'slug' => Str::slug(fake()->unique()->company()),
        ]);
        $brand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => fake()->company().' Brand',
            'slug' => Str::slug(fake()->unique()->company()),
        ]);
        $title = fake()->sentence(5);

        return [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'type' => fake()->randomElement(ContentAsset::TYPES),
            'status' => fake()->randomElement(ContentAsset::STATUSES),
            'title' => $title,
            'slug' => Str::slug($title),
            'language' => 'en',
            'locale' => 'en_US',
            'source' => fake()->randomElement(['manual', 'demo.import', 'integration']),
            'source_url' => null,
            'canonical_url' => fake()->optional()->url(),
            'excerpt' => fake()->sentence(18),
            'body' => fake()->paragraphs(3, true),
            'metadata' => ['demo' => true],
            'seo_metadata' => ['title' => $title],
            'published_at' => null,
            'first_published_at' => null,
            'last_refreshed_at' => null,
        ];
    }

    public function forBrand(Brand $brand): static
    {
        return $this->state(fn () => [
            'account_id' => $brand->account_id,
            'brand_id' => $brand->id,
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => 'draft']);
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => 'published',
            'published_at' => now()->subDay(),
            'first_published_at' => now()->subDay(),
        ]);
    }
}
