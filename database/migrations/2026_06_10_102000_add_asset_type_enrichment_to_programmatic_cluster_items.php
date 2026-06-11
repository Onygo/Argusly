<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programmatic_cluster_items', function (Blueprint $table): void {
            $table->string('growth_asset_type', 80)->nullable()->after('asset_type')->index();
            $table->unsignedInteger('recommended_word_count_min')->nullable()->after('business_value_score');
            $table->unsignedInteger('recommended_word_count_max')->nullable()->after('recommended_word_count_min');
            $table->json('recommended_schema_types')->nullable()->after('recommended_word_count_max');
            $table->string('recommended_cta', 120)->nullable()->after('recommended_schema_types');
            $table->string('internal_linking_role', 80)->nullable()->after('recommended_cta');
            $table->json('briefing_requirements')->nullable()->after('internal_linking_role');
            $table->json('ai_visibility_requirements')->nullable()->after('briefing_requirements');
            $table->json('seo_requirements')->nullable()->after('ai_visibility_requirements');
        });
    }

    public function down(): void
    {
        Schema::table('programmatic_cluster_items', function (Blueprint $table): void {
            $table->dropColumn([
                'growth_asset_type',
                'recommended_word_count_min',
                'recommended_word_count_max',
                'recommended_schema_types',
                'recommended_cta',
                'internal_linking_role',
                'briefing_requirements',
                'ai_visibility_requirements',
                'seo_requirements',
            ]);
        });
    }
};
