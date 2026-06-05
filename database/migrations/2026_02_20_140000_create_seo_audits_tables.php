<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('seo_audits')) {
            Schema::create('seo_audits', function (Blueprint $table) {
                $table->id();
                $table->uuid('workspace_id');
                $table->uuid('client_site_id');
                $table->timestamp('started_at');
                $table->timestamp('finished_at')->nullable();
                $table->enum('status', ['running', 'completed', 'failed'])->default('running');
                $table->unsignedInteger('pages_crawled')->default(0);
                $table->json('issue_counts')->nullable();
                $table->text('error_message')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->index(['client_site_id', 'status', 'created_at'], 'seo_audits_site_status_created_idx');
                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign('client_site_id')->references('id')->on('client_sites')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('seo_audit_pages')) {
            Schema::create('seo_audit_pages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('seo_audit_id')->constrained('seo_audits')->cascadeOnDelete();
                $table->string('url', 700);
                $table->unsignedSmallInteger('status_code')->nullable();
                $table->string('title', 512)->nullable();
                $table->string('meta_description', 320)->nullable();
                $table->string('canonical_url', 2048)->nullable();
                $table->string('robots_meta', 255)->nullable();
                $table->string('h1', 512)->nullable();
                $table->unsignedInteger('word_count')->default(0);
                $table->unsignedInteger('internal_links_count')->default(0);
                $table->unsignedInteger('broken_links_count')->default(0);
                $table->timestamps();

                $table->unique(['seo_audit_id', 'url'], 'seo_audit_pages_audit_url_unique');
                $table->index(['seo_audit_id', 'status_code'], 'seo_audit_pages_audit_status_idx');
            });
        }

        if (! Schema::hasTable('seo_audit_issues')) {
            Schema::create('seo_audit_issues', function (Blueprint $table) {
                $table->id();
                $table->foreignId('seo_audit_id')->constrained('seo_audits')->cascadeOnDelete();
                $table->foreignId('seo_audit_page_id')->nullable()->constrained('seo_audit_pages')->nullOnDelete();
                $table->enum('severity', ['info', 'warning', 'error']);
                $table->string('code', 80);
                $table->string('title', 160);
                $table->text('description')->nullable();
                $table->text('recommendation')->nullable();
                $table->json('context_json')->nullable();
                $table->timestamps();

                $table->index(['seo_audit_id', 'severity'], 'seo_audit_issues_audit_severity_idx');
                $table->index(['code'], 'seo_audit_issues_code_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_audit_issues');
        Schema::dropIfExists('seo_audit_pages');
        Schema::dropIfExists('seo_audits');
    }
};
