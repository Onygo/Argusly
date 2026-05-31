<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Property;
use App\Models\PublishingChannel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PublishingChannel>
 */
class PublishingChannelFactory extends Factory
{
    protected $model = PublishingChannel::class;

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
            'property_id' => null,
            'provider' => fake()->randomElement(PublishingChannel::PROVIDERS),
            'name' => fake()->company().' Channel',
            'status' => 'draft',
            'credentials' => null,
            'settings' => ['demo' => true],
            'last_connected_at' => null,
        ];
    }

    public function forBrand(Brand $brand): static
    {
        return $this->state(fn () => [
            'account_id' => $brand->account_id,
            'brand_id' => $brand->id,
        ]);
    }

    public function forProperty(Property $property): static
    {
        return $this->state(fn () => [
            'account_id' => $property->account_id,
            'brand_id' => $property->brand_id,
            'property_id' => $property->id,
        ]);
    }
}
