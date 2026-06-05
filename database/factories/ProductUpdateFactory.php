<?php

namespace Database\Factories;

use App\Models\ProductUpdate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductUpdate>
 */
class ProductUpdateFactory extends Factory
{
    protected $model = ProductUpdate::class;

    public function definition(): array
    {
        return [
            'title' => ucfirst($this->faker->words(4, true)),
            'summary' => $this->faker->sentence(14),
            'body_markdown' => "## Update\n\n" . $this->faker->paragraph(3),
            'version' => 'v0.' . $this->faker->numberBetween(1, 9) . '.' . $this->faker->numberBetween(0, 9),
            'tags' => ['release'],
            'is_public' => true,
            'published_at' => now()->subDay(),
        ];
    }
}
