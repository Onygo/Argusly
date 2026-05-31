<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    protected $model = Property::class;

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

        return [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => fake()->company().' Website',
            'type' => fake()->randomElement(Property::TYPES),
            'url' => fake()->url(),
            'primary_language' => fake()->randomElement(['en', 'nl', 'de']),
            'settings' => ['demo' => true],
            'status' => 'active',
        ];
    }

    public function forBrand(Brand $brand): static
    {
        return $this->state(fn () => [
            'account_id' => $brand->account_id,
            'brand_id' => $brand->id,
        ]);
    }
}
