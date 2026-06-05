<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('credit_cost_catalog')->updateOrInsert(
            ['code' => 'content_generation'],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'Content Generation',
                'description' => 'General first draft and generated asset creation.',
                'category' => 'content',
                'default_cost' => 100,
                'minimum_cost' => null,
                'maximum_cost' => null,
                'cost_type' => 'fixed',
                'status' => 'active',
                'metadata' => json_encode(['canonical_code' => 'blog_generation'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        DB::table('credit_cost_catalog')->updateOrInsert(
            ['code' => 'blog_generation'],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'Blog Generation',
                'description' => null,
                'category' => 'content',
                'default_cost' => 100,
                'minimum_cost' => null,
                'maximum_cost' => null,
                'cost_type' => 'fixed',
                'status' => 'active',
                'metadata' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('credit_cost_catalog')
            ->where('code', 'content_generation')
            ->update(['status' => 'inactive', 'updated_at' => now()]);
    }
};
