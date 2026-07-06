<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serp_query_sets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->string('name', 220);
            $table->text('description')->nullable();
            $table->string('locale', 40)->nullable()->index();
            $table->string('country', 2)->nullable()->index();
            $table->string('device', 40)->default('desktop')->index();
            $table->string('search_engine', 80)->default('google')->index();
            $table->string('provider_key', 80)->default('manual')->index();
            $table->string('cadence', 60)->nullable();
            $table->string('status', 40)->default('active')->index();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->index(['workspace_id', 'status', 'provider_key'], 'serp_query_sets_workspace_status_provider_idx');
        });

        Schema::create('serp_queries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('serp_query_set_id')->index();
            $table->text('query');
            $table->char('query_hash', 64)->index();
            $table->string('locale', 40)->nullable()->index();
            $table->string('country', 2)->nullable()->index();
            $table->string('device', 40)->default('desktop')->index();
            $table->string('search_engine', 80)->default('google')->index();
            $table->string('keyword_intent', 80)->nullable()->index();
            $table->unsignedInteger('search_volume')->nullable();
            $table->unsignedSmallInteger('priority')->default(100)->index();
            $table->string('status', 40)->default('active')->index();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('serp_query_set_id')->references('id')->on('serp_query_sets')->cascadeOnDelete();
            $table->unique(['serp_query_set_id', 'query_hash', 'search_engine', 'country', 'device'], 'serp_queries_set_query_scope_unique');
            $table->index(['workspace_id', 'status', 'priority'], 'serp_queries_workspace_status_priority_idx');
        });

        Schema::table('page_serp_observations', function (Blueprint $table): void {
            $table->uuid('serp_query_set_id')->nullable()->after('page_snapshot_id')->index();
            $table->uuid('serp_query_id')->nullable()->after('serp_query_set_id')->index();
            $table->foreign('serp_query_set_id')->references('id')->on('serp_query_sets')->nullOnDelete();
            $table->foreign('serp_query_id')->references('id')->on('serp_queries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('page_serp_observations', function (Blueprint $table): void {
            $table->dropForeign(['serp_query_set_id']);
            $table->dropForeign(['serp_query_id']);
            $table->dropColumn(['serp_query_set_id', 'serp_query_id']);
        });

        Schema::dropIfExists('serp_queries');
        Schema::dropIfExists('serp_query_sets');
    }
};
