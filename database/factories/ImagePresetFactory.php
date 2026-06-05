<?php

namespace Database\Factories;

use App\Models\ImagePreset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImagePreset>
 */
class ImagePresetFactory extends Factory
{
    protected $model = ImagePreset::class;

    public function definition(): array
    {
        return [
            'name' => ucfirst($this->faker->words(2, true)) . ' Style',
            'instructions' => implode("\n", [
                $this->faker->sentence(4),
                $this->faker->sentence(5),
                $this->faker->sentence(3),
            ]),
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }
}
