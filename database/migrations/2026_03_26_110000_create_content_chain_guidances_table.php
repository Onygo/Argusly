<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_chain_guidances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('content_id');
            $table->boolean('is_source_enabled')->default(false);
            $table->string('preferred_angle', 191)->nullable();
            $table->string('goal_type', 64)->nullable();
            $table->string('priority', 32)->default('medium');
            $table->string('target_keyword', 191)->nullable();
            $table->string('target_audience', 191)->nullable();
            $table->string('target_intent', 64)->nullable();
            $table->string('explicit_topic', 191)->nullable();
            $table->text('editor_notes')->nullable();
            $table->string('inline_link_mode', 32)->default('review');
            $table->boolean('allow_heading_links')->default(false);
            $table->unsignedSmallInteger('max_inline_links')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('content_id', 'content_chain_guidances_content_unq');
            $table->index(['workspace_id', 'is_source_enabled'], 'content_chain_guidances_workspace_source_idx');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('content_id')->references('id')->on('contents')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_chain_guidances');
    }
};
