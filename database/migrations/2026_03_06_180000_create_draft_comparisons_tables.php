<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('draft_comparisons', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('brief_id')->index();
            $table->uuid('content_id')->nullable()->index();
            $table->uuid('client_site_id')->index();
            $table->unsignedBigInteger('created_by_user_id')->nullable()->index();

            $table->string('mode', 32)->default('single');
            $table->string('status', 32)->default('queued');
            $table->unsignedInteger('requested_max_output_tokens')->nullable();
            $table->unsignedInteger('estimated_credits')->default(0);
            $table->unsignedInteger('credits_used')->default(0);
            $table->unsignedInteger('items_total')->default(0);
            $table->unsignedInteger('items_done')->default(0);
            $table->unsignedInteger('items_failed')->default(0);

            $table->uuid('winner_draft_id')->nullable()->index();
            $table->uuid('hybrid_draft_id')->nullable()->index();
            $table->string('hybrid_status', 32)->default('idle');
            $table->text('hybrid_last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('hybrid_started_at')->nullable();
            $table->timestamp('hybrid_completed_at')->nullable();
            $table->text('last_error')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('brief_id')->references('id')->on('briefs')->cascadeOnDelete();
            $table->foreign('content_id')->references('id')->on('contents')->nullOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->cascadeOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('winner_draft_id')->references('id')->on('drafts')->nullOnDelete();
            $table->foreign('hybrid_draft_id')->references('id')->on('drafts')->nullOnDelete();
        });

        Schema::create('draft_comparison_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('draft_comparison_id')->index();
            $table->uuid('draft_id')->nullable()->index();

            $table->unsignedInteger('sort_order')->default(1);
            $table->string('provider', 64);
            $table->string('model', 190);
            $table->string('status', 32)->default('queued');
            $table->unsignedInteger('credit_cost')->default(0);
            $table->unsignedInteger('charged_credits')->default(0);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->timestamp('generation_started_at')->nullable();
            $table->timestamp('generation_completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metrics')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['draft_comparison_id', 'provider', 'model'], 'draft_compare_items_provider_model_unique');
            $table->foreign('draft_comparison_id')->references('id')->on('draft_comparisons')->cascadeOnDelete();
            $table->foreign('draft_id')->references('id')->on('drafts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('draft_comparison_items');
        Schema::dropIfExists('draft_comparisons');
    }
};
