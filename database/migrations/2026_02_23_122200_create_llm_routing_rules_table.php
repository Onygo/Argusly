<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_routing_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope_type', 16); // global|workspace|site
            $table->string('scope_id', 64)->nullable();
            $table->string('feature', 64);
            $table->string('modality', 16)->default('text');
            $table->boolean('inherit_global')->default(true);
            $table->string('provider', 32)->nullable();
            $table->string('model', 120)->nullable();
            $table->boolean('fallback_enabled')->default(false);
            $table->string('fallback_provider', 32)->nullable();
            $table->string('fallback_model', 120)->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['scope_type', 'scope_id', 'feature'], 'llm_rules_scope_feature_unique');
            $table->index(['scope_type', 'scope_id'], 'llm_rules_scope_idx');
            $table->index(['feature', 'modality'], 'llm_rules_feature_modality_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_routing_rules');
    }
};
