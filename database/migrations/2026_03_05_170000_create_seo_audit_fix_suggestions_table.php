<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seo_audit_fix_suggestions')) {
            return;
        }

        Schema::create('seo_audit_fix_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->uuid('workspace_id')->nullable();
            $table->uuid('client_site_id')->nullable();
            $table->foreignId('seo_audit_id')->constrained('seo_audits')->cascadeOnDelete();
            $table->foreignId('seo_audit_page_id')->nullable()->constrained('seo_audit_pages')->nullOnDelete();
            $table->string('issue_code', 80);
            $table->string('status', 32)->default('pending');
            $table->json('input_snapshot')->nullable();
            $table->json('suggestion')->nullable();
            $table->json('token_usage')->nullable();
            $table->unsignedInteger('credits_reserved')->default(0);
            $table->unsignedInteger('credits_charged')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('applied_by')->nullable();
            $table->timestamps();

            $table->index('seo_audit_id', 'seo_audit_fix_suggestions_audit_idx');
            $table->index('seo_audit_page_id', 'seo_audit_fix_suggestions_page_idx');
            $table->index('issue_code', 'seo_audit_fix_suggestions_issue_idx');
            $table->index('status', 'seo_audit_fix_suggestions_status_idx');
            $table->unique(
                ['seo_audit_id', 'seo_audit_page_id', 'issue_code'],
                'seo_audit_fix_suggestions_issue_unique'
            );

            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('applied_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_audit_fix_suggestions');
    }
};
