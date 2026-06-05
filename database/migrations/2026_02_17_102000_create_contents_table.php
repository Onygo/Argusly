<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->string('title');
            $table->enum('type', ['knowledge_base', 'article', 'seo_page', 'press_release'])->default('article');
            $table->enum('status', ['brief_received', 'draft', 'review', 'approved', 'published'])->default('brief_received');
            $table->enum('source', ['wp', 'manual', 'api'])->default('api');
            $table->string('external_id')->nullable();
            $table->string('delivery_status', 32)->default('pending');
            $table->string('generation_mode', 32)->default('balanced');
            $table->uuid('current_revision_id')->nullable();
            $table->timestamp('last_feedback_at')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->unique(['workspace_id', 'external_id']);
            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'type']);
            $table->index(['workspace_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contents');
    }
};
