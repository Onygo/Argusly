<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seo_apply_logs')) {
            return;
        }

        Schema::create('seo_apply_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('suggestion_id')->constrained('seo_audit_fix_suggestions')->cascadeOnDelete();
            $table->foreignId('seo_audit_id')->nullable()->constrained('seo_audits')->nullOnDelete();
            $table->foreignId('seo_audit_page_id')->nullable()->constrained('seo_audit_pages')->nullOnDelete();
            $table->string('issue_code', 80)->nullable();
            $table->uuid('content_id');
            $table->uuid('draft_id')->nullable();
            $table->unsignedBigInteger('applied_by')->nullable();
            $table->string('apply_target', 64)->default('content_only');
            $table->json('changed_fields')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('apply_status', 32)->default('applied');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->unique('suggestion_id', 'seo_apply_logs_suggestion_unique');
            $table->index(['seo_audit_id', 'created_at'], 'seo_apply_logs_audit_created_idx');
            $table->index(['content_id', 'created_at'], 'seo_apply_logs_content_created_idx');
            $table->index(['apply_status', 'created_at'], 'seo_apply_logs_status_created_idx');
            $table->index(['applied_by', 'created_at'], 'seo_apply_logs_applied_by_created_idx');

            $table->foreign('content_id')->references('id')->on('contents')->cascadeOnDelete();
            $table->foreign('draft_id')->references('id')->on('drafts')->nullOnDelete();
            $table->foreign('applied_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_apply_logs');
    }
};
