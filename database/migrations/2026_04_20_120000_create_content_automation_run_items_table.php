<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_automation_run_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('automation_run_id');
            $table->uuid('automation_id');
            $table->unsignedInteger('chain_index')->default(0);
            $table->string('status', 32)->default('planned');
            $table->string('failure_stage', 64)->nullable();
            $table->string('last_error_code', 128)->nullable();
            $table->text('last_error_message')->nullable();
            $table->uuid('content_id')->nullable();
            $table->uuid('draft_id')->nullable();
            $table->uuid('brief_id')->nullable();
            $table->uuid('client_site_id')->nullable();
            $table->string('locale', 12)->nullable();
            $table->string('title')->nullable();
            $table->string('provider', 80)->nullable();
            $table->string('model', 160)->nullable();
            $table->string('prompt_hash', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['automation_run_id', 'chain_index'], 'automation_run_items_run_index_idx');
            $table->index(['automation_id', 'status'], 'automation_run_items_automation_status_idx');
            $table->index(['content_id'], 'automation_run_items_content_idx');

            $table->foreign('automation_run_id')
                ->references('id')
                ->on('content_automation_runs')
                ->cascadeOnDelete();
            $table->foreign('automation_id')
                ->references('id')
                ->on('content_automations')
                ->cascadeOnDelete();
            $table->foreign('content_id')
                ->references('id')
                ->on('contents')
                ->nullOnDelete();
            $table->foreign('draft_id')
                ->references('id')
                ->on('drafts')
                ->nullOnDelete();
            $table->foreign('brief_id')
                ->references('id')
                ->on('briefs')
                ->nullOnDelete();
            $table->foreign('client_site_id')
                ->references('id')
                ->on('client_sites')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_automation_run_items');
    }
};
