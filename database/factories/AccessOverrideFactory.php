<?php

namespace Database\Factories;

use App\Enums\AccessOverrideStatus;
use App\Enums\AccessOverrideType;
use App\Models\AccessOverride;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AccessOverride>
 */
class AccessOverrideFactory extends Factory
{
    protected $model = AccessOverride::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'workspace_id' => null,
            'type' => AccessOverrideType::EARLY_ACCESS,
            'status' => AccessOverrideStatus::ACTIVE,
            'starts_at' => now(),
            'ends_at' => now()->addDays(30),
            'reason' => $this->faker->sentence(),
            'notes' => $this->faker->paragraph(),
            'created_by_user_id' => null,
            'ended_by_user_id' => null,
            'ended_at' => null,
            'metadata' => [],
        ];
    }
}
