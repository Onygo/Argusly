<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visibility_checks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->index();
            $table->string('query', 500);
            $table->string('brand');
            $table->string('status')->default('active')->index();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'brand_id', 'provider', 'query'], 'visibility_checks_scope_unique');
            $table->index(['account_id', 'brand_id', 'status']);
        });

        Schema::create('visibility_results', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visibility_check_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider')->index();
            $table->text('query');
            $table->string('brand');
            $table->unsignedTinyInteger('score')->nullable();
            $table->unsignedSmallInteger('position')->nullable();
            $table->boolean('mention_found')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamp('captured_at')->index();

            $table->index(['account_id', 'brand_id', 'captured_at']);
            $table->index(['visibility_check_id', 'captured_at']);
        });

        Schema::create('visibility_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->nullable()->index();
            $table->unsignedTinyInteger('score')->nullable();
            $table->unsignedSmallInteger('position')->nullable();
            $table->boolean('mention_found')->default(false);
            $table->unsignedInteger('results_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('captured_at')->index();

            $table->index(['account_id', 'brand_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visibility_snapshots');
        Schema::dropIfExists('visibility_results');
        Schema::dropIfExists('visibility_checks');
    }
};
