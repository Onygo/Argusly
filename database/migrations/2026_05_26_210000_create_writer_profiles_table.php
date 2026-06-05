<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('writer_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('brand_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('source_type', 32)->default('manual')->index();
            $table->string('profile_scope', 32)->default('author')->index();
            $table->text('tone_summary')->nullable();
            $table->text('writing_style_summary')->nullable();
            $table->text('structure_summary')->nullable();
            $table->text('vocabulary_notes')->nullable();
            $table->text('formatting_preferences')->nullable();
            $table->json('do_rules')->nullable();
            $table->json('dont_rules')->nullable();
            $table->json('example_patterns')->nullable();
            $table->decimal('confidence_score', 4, 3)->default(0);
            $table->string('status', 32)->default('draft')->index();
            $table->boolean('retain_source_text')->default(false);
            $table->json('channel_defaults')->nullable();
            $table->timestamp('last_analyzed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'profile_scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('writer_profiles');
    }
};
