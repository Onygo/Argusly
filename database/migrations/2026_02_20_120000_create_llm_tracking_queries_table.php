<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_tracking_queries', function (Blueprint $table) {
            $table->id();
            $table->uuid('workspace_id');
            $table->uuid('client_site_id');
            $table->string('name', 120);
            $table->text('query_text');
            $table->json('brand_terms')->nullable();
            $table->json('competitor_terms')->nullable();
            $table->json('target_urls')->nullable();
            $table->string('locale', 16)->default('en');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'client_site_id', 'is_active'], 'llm_track_queries_scope_active_idx');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_tracking_queries');
    }
};
