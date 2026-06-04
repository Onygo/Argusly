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
        Schema::create('plan_features', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->nullable()->constrained()->nullOnDelete();
            $table->string('feature')->index();
            $table->string('name')->nullable();
            $table->boolean('enabled')->default(true)->index();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'feature']);
        });

        Schema::create('plan_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('key')->index();
            $table->boolean('enabled')->default(true)->index();
            $table->json('value')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'key']);
        });

        Schema::create('feature_limits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('feature')->index();
            $table->string('limit_key')->index();
            $table->string('name')->nullable();
            $table->unsignedInteger('value')->nullable();
            $table->boolean('unlimited')->default(false)->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'feature', 'limit_key']);
        });

        Schema::create('account_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->nullable()->constrained()->nullOnDelete();
            $table->string('feature')->index();
            $table->string('limit_key')->nullable()->index();
            $table->boolean('enabled')->nullable()->index();
            $table->unsignedInteger('value')->nullable();
            $table->boolean('unlimited')->default(false)->index();
            $table->string('status')->default('active')->index();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'feature', 'limit_key']);
            $table->index(['account_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_entitlements');
        Schema::dropIfExists('feature_limits');
        Schema::dropIfExists('plan_entitlements');
        Schema::dropIfExists('plan_features');
    }
};
