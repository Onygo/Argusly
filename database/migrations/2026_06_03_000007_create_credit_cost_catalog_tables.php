<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('credit_usage_stats');
        Schema::dropIfExists('credit_cost_overrides');
        Schema::dropIfExists('credit_cost_catalog');

        Schema::create('credit_cost_catalog', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->index();
            $table->unsignedInteger('default_cost');
            $table->unsignedInteger('minimum_cost')->nullable();
            $table->unsignedInteger('maximum_cost')->nullable();
            $table->string('cost_type')->default('fixed')->index();
            $table->string('status')->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['category', 'status']);
        });

        Schema::create('credit_cost_overrides', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('credit_cost_catalog_id')->constrained('credit_cost_catalog')->cascadeOnDelete();
            $table->unsignedInteger('override_cost');
            $table->string('status')->default('active')->index();
            $table->timestamps();

            $table->unique(['account_id', 'brand_id', 'credit_cost_catalog_id'], 'credit_cost_overrides_scope_unique');
            $table->index(['account_id', 'status']);
            $table->index(['brand_id', 'status']);
        });

        Schema::create('credit_usage_stats', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('catalog_code')->index();
            $table->unsignedInteger('credits_used')->default(0);
            $table->unsignedInteger('executions')->default(0);
            $table->timestamp('period_start')->index();
            $table->timestamp('period_end')->index();
            $table->timestamps();

            $table->unique(['account_id', 'brand_id', 'catalog_code', 'period_start', 'period_end'], 'credit_usage_stats_unique_period');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credit_usage_stats');
        Schema::dropIfExists('credit_cost_overrides');
        Schema::dropIfExists('credit_cost_catalog');
    }
};
