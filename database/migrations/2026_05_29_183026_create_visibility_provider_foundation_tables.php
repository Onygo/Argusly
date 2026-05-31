<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visibility_prompt_templates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('prompt');
            $table->string('intent')->nullable()->index();
            $table->string('locale')->nullable()->index();
            $table->string('market')->nullable()->index();
            $table->string('persona')->nullable();
            $table->string('status')->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'brand_id', 'name'], 'visibility_prompt_templates_scope_name_unique');
            $table->index(['account_id', 'brand_id', 'status']);
        });

        Schema::create('visibility_provider_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visibility_check_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider')->index();
            $table->string('model')->nullable();
            $table->foreignId('prompt_template_id')->nullable()->constrained('visibility_prompt_templates')->nullOnDelete();
            $table->text('query');
            $table->longText('raw_response')->nullable();
            $table->longText('normalized_answer')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('cost_credits')->default(0);
            $table->string('status')->default('pending')->index();
            $table->timestamp('captured_at')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'brand_id', 'captured_at']);
            $table->index(['visibility_check_id', 'captured_at']);
            $table->index(['provider', 'status']);
        });

        Schema::create('visibility_citations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_run_id')->constrained('visibility_provider_runs')->cascadeOnDelete();
            $table->text('url');
            $table->string('domain')->nullable()->index();
            $table->string('title')->nullable();
            $table->text('snippet')->nullable();
            $table->unsignedSmallInteger('rank')->nullable();
            $table->unsignedTinyInteger('trust_score')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'brand_id']);
            $table->index(['provider_run_id', 'rank']);
        });

        Schema::create('visibility_answer_entities', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_run_id')->constrained('visibility_provider_runs')->cascadeOnDelete();
            $table->string('entity_name');
            $table->string('entity_type')->nullable()->index();
            $table->string('sentiment')->nullable()->index();
            $table->unsignedSmallInteger('position')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'brand_id']);
            $table->index(['provider_run_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visibility_answer_entities');
        Schema::dropIfExists('visibility_citations');
        Schema::dropIfExists('visibility_provider_runs');
        Schema::dropIfExists('visibility_prompt_templates');
    }
};
