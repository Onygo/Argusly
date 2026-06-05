<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_series_generation_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('series_id');
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->uuid('credit_ledger_entry_id')->nullable();
            $table->unsignedSmallInteger('total_articles')->default(0);
            $table->unsignedSmallInteger('completed_articles')->default(0);
            $table->unsignedSmallInteger('failed_articles')->default(0);
            $table->string('status', 32)->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['series_id', 'status'], 'content_series_gen_runs_series_status_idx');
            $table->index(['series_id', 'created_at'], 'content_series_gen_runs_series_created_idx');
            $table->foreign('series_id')->references('id')->on('content_series')->cascadeOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('content_series_generation_run_articles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('run_id');
            $table->uuid('series_id');
            $table->unsignedSmallInteger('article_number');
            $table->string('title', 255)->nullable();
            $table->string('status', 32)->default('pending');
            $table->uuid('content_id')->nullable();
            $table->uuid('brief_id')->nullable();
            $table->uuid('draft_id')->nullable();
            $table->string('slug', 255)->nullable();
            $table->string('planned_url', 2048)->nullable();
            $table->json('internal_links_to')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'article_number'], 'content_series_gen_run_articles_run_article_unique');
            $table->index(['series_id', 'status'], 'content_series_gen_run_articles_series_status_idx');
            $table->foreign('run_id')->references('id')->on('content_series_generation_runs')->cascadeOnDelete();
            $table->foreign('series_id')->references('id')->on('content_series')->cascadeOnDelete();
            $table->foreign('content_id')->references('id')->on('contents')->nullOnDelete();
            $table->foreign('brief_id')->references('id')->on('briefs')->nullOnDelete();
            $table->foreign('draft_id')->references('id')->on('drafts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('content_series_generation_run_articles', function (Blueprint $table): void {
            $table->dropForeign(['run_id']);
            $table->dropForeign(['series_id']);
            $table->dropForeign(['content_id']);
            $table->dropForeign(['brief_id']);
            $table->dropForeign(['draft_id']);
        });

        Schema::dropIfExists('content_series_generation_run_articles');

        Schema::table('content_series_generation_runs', function (Blueprint $table): void {
            $table->dropForeign(['series_id']);
            $table->dropForeign(['requested_by']);
        });

        Schema::dropIfExists('content_series_generation_runs');
    }
};
