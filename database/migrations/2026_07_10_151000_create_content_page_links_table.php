<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_page_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('content_id')->index();
            $table->uuid('monitored_page_id')->index();
            $table->string('link_type', 80)->index();
            $table->boolean('is_primary')->default(false)->index();
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('content_id')->references('id')->on('contents')->cascadeOnDelete();
            $table->foreign('monitored_page_id')->references('id')->on('monitored_pages')->cascadeOnDelete();

            $table->unique(
                ['workspace_id', 'content_id', 'monitored_page_id', 'link_type'],
                'content_page_links_unique_link'
            );
            $table->index(['workspace_id', 'client_site_id'], 'content_page_links_workspace_site_idx');
            $table->index(['content_id', 'link_type'], 'content_page_links_content_type_idx');
            $table->index(['monitored_page_id', 'link_type'], 'content_page_links_page_type_idx');
            $table->index(['content_id', 'link_type', 'is_primary'], 'content_page_links_primary_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_page_links');
    }
};
