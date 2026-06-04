<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_providers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('provider')->unique();
            $table->string('name');
            $table->string('status')->default('active')->index();
            $table->string('base_url')->nullable();
            $table->string('api_key_env')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('llm_models', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('provider_id')->constrained('llm_providers')->cascadeOnDelete();
            $table->string('model');
            $table->string('name');
            $table->string('type')->index();
            $table->unsignedInteger('context_window')->nullable();
            $table->boolean('supports_json')->default(false);
            $table->boolean('supports_tools')->default(false);
            $table->boolean('supports_vision')->default(false);
            $table->boolean('supports_streaming')->default(false);
            $table->decimal('input_cost_per_1k', 10, 6)->nullable();
            $table->decimal('output_cost_per_1k', 10, 6)->nullable();
            $table->string('status')->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['provider_id', 'model']);
            $table->index(['provider_id', 'type', 'status']);
        });

        Schema::create('llm_settings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('default_provider_id')->nullable()->constrained('llm_providers')->nullOnDelete();
            $table->foreignId('default_model_id')->nullable()->constrained('llm_models')->nullOnDelete();
            $table->foreignId('fallback_provider_id')->nullable()->constrained('llm_providers')->nullOnDelete();
            $table->foreignId('fallback_model_id')->nullable()->constrained('llm_models')->nullOnDelete();
            $table->decimal('temperature', 4, 2)->nullable();
            $table->unsignedInteger('max_tokens')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'brand_id']);
            $table->index(['account_id', 'brand_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_settings');
        Schema::dropIfExists('llm_models');
        Schema::dropIfExists('llm_providers');
    }
};
