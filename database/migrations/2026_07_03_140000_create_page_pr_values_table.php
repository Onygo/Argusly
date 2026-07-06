<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_pr_values', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('monitored_page_id')->index();
            $table->uuid('page_snapshot_id')->index();
            $table->uuid('page_content_extraction_id')->nullable()->index();
            $table->string('model_key', 120)->index();
            $table->string('model_version', 80)->index();
            $table->decimal('score', 8, 2)->default(0);
            $table->decimal('estimated_value_amount', 14, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->decimal('confidence', 8, 2)->default(0);
            $table->json('breakdown_json')->nullable();
            $table->timestamp('calculated_at')->nullable()->index();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('monitored_page_id')->references('id')->on('monitored_pages')->cascadeOnDelete();
            $table->foreign('page_snapshot_id')->references('id')->on('page_snapshots')->cascadeOnDelete();
            $table->foreign('page_content_extraction_id')->references('id')->on('page_content_extractions')->nullOnDelete();
            $table->unique(['page_snapshot_id', 'model_key', 'model_version'], 'page_pr_values_snapshot_model_version_unique');
            $table->index(['workspace_id', 'model_key', 'score'], 'page_pr_values_workspace_model_score_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_pr_values');
    }
};
