<?php

use App\Models\Plan;
use App\Models\PlanFeature;
use Database\Seeders\PlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds draft compare feature gates per plan', function () {
    $this->seed(PlansSeeder::class);

    $starter = Plan::query()->where('slug', 'platform_250')->firstOrFail();
    $growth = Plan::query()->where('slug', 'platform_500')->firstOrFail();
    $enterprise = Plan::query()->where('slug', 'enterprise_custom')->firstOrFail();

    $starterEnabled = PlanFeature::query()
        ->where('plan_id', $starter->id)
        ->where('feature_key', 'draft_compare_enabled')
        ->firstOrFail();

    $growthMax = PlanFeature::query()
        ->where('plan_id', $growth->id)
        ->where('feature_key', 'draft_compare_max_models')
        ->firstOrFail();

    $scaleHybrid = PlanFeature::query()
        ->where('plan_id', $enterprise->id)
        ->where('feature_key', 'draft_compare_hybrid_enabled')
        ->firstOrFail();

    expect((string) $starterEnabled->value_type)->toBe('bool')
        ->and((bool) $starterEnabled->value_bool)->toBeTrue()
        ->and((string) $growthMax->value_type)->toBe('int')
        ->and((int) $growthMax->value_int)->toBe(3)
        ->and((bool) $scaleHybrid->value_bool)->toBeTrue();
});
