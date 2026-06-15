<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_feed_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->nullable()->index();
            $table->foreignId('organization_id')->nullable()->index()->constrained('organizations')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('source_type')->nullable();
            $table->string('source_id')->nullable();
            $table->string('source_signature')->unique();
            $table->string('category', 64)->index();
            $table->string('assistant_state', 32)->index();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->text('i_found')->nullable();
            $table->text('i_recommend')->nullable();
            $table->text('i_prepared')->nullable();
            $table->text('i_completed')->nullable();
            $table->text('i_need_your_input')->nullable();
            $table->unsignedTinyInteger('priority_score')->default(50)->index();
            $table->string('priority_label', 16)->default('medium')->index();
            $table->string('status', 32)->default('active')->index();
            $table->string('primary_cta_label')->nullable();
            $table->string('primary_cta_url')->nullable();
            $table->string('secondary_cta_label')->nullable();
            $table->string('secondary_cta_url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('visible_at')->nullable()->index();
            $table->timestamp('read_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
            $table->index(['workspace_id', 'status', 'priority_score'], 'assistant_feed_workspace_status_priority_idx');
            $table->index(['workspace_id', 'assistant_state', 'created_at'], 'assistant_feed_workspace_state_created_idx');
            $table->index(['source_type', 'source_id'], 'assistant_feed_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_feed_items');
    }
};
