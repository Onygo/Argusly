<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_campaign_matches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('monitored_page_id')->index();
            $table->uuid('page_snapshot_id')->nullable()->index();
            $table->uuid('page_content_extraction_id')->nullable()->index();
            $table->uuid('campaign_id')->index();
            $table->string('match_type', 80)->index();
            $table->decimal('match_score', 8, 4)->default(0);
            $table->json('evidence_json')->nullable();
            $table->timestamp('observed_at')->index();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('monitored_page_id')->references('id')->on('monitored_pages')->cascadeOnDelete();
            $table->foreign('page_snapshot_id')->references('id')->on('page_snapshots')->nullOnDelete();
            $table->foreign('page_content_extraction_id')->references('id')->on('page_content_extractions')->nullOnDelete();
            $table->foreign('campaign_id')->references('id')->on('campaigns')->cascadeOnDelete();
            $table->unique(['monitored_page_id', 'campaign_id', 'match_type'], 'page_campaign_matches_unique');
        });

        Schema::create('page_competitor_matches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('monitored_page_id')->index();
            $table->uuid('page_snapshot_id')->nullable()->index();
            $table->uuid('page_content_extraction_id')->nullable()->index();
            $table->foreignId('site_competitor_id')->constrained('site_competitors')->cascadeOnDelete();
            $table->string('match_type', 80)->index();
            $table->decimal('match_score', 8, 4)->default(0);
            $table->json('evidence_json')->nullable();
            $table->timestamp('observed_at')->index();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('monitored_page_id')->references('id')->on('monitored_pages')->cascadeOnDelete();
            $table->foreign('page_snapshot_id')->references('id')->on('page_snapshots')->nullOnDelete();
            $table->foreign('page_content_extraction_id')->references('id')->on('page_content_extractions')->nullOnDelete();
            $table->unique(['monitored_page_id', 'site_competitor_id', 'match_type'], 'page_competitor_matches_unique');
        });

        Schema::create('page_brand_matches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('monitored_page_id')->index();
            $table->uuid('page_snapshot_id')->nullable()->index();
            $table->uuid('page_content_extraction_id')->nullable()->index();
            $table->string('brand_ref_type', 120)->nullable();
            $table->string('brand_ref_id', 80)->nullable();
            $table->string('brand_key', 160)->nullable()->index();
            $table->string('brand_name', 220);
            $table->string('match_type', 80)->index();
            $table->decimal('match_score', 8, 4)->default(0);
            $table->json('evidence_json')->nullable();
            $table->timestamp('observed_at')->index();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('monitored_page_id')->references('id')->on('monitored_pages')->cascadeOnDelete();
            $table->foreign('page_snapshot_id')->references('id')->on('page_snapshots')->nullOnDelete();
            $table->foreign('page_content_extraction_id')->references('id')->on('page_content_extractions')->nullOnDelete();
            $table->unique(['monitored_page_id', 'brand_key', 'match_type'], 'page_brand_matches_unique');
        });

        Schema::create('page_market_pack_matches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('monitored_page_id')->index();
            $table->uuid('page_snapshot_id')->nullable()->index();
            $table->uuid('page_content_extraction_id')->nullable()->index();
            $table->string('market_pack_key', 160)->index();
            $table->string('market_pack_name', 220);
            $table->string('match_type', 80)->index();
            $table->decimal('match_score', 8, 4)->default(0);
            $table->json('evidence_json')->nullable();
            $table->timestamp('observed_at')->index();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('monitored_page_id')->references('id')->on('monitored_pages')->cascadeOnDelete();
            $table->foreign('page_snapshot_id')->references('id')->on('page_snapshots')->nullOnDelete();
            $table->foreign('page_content_extraction_id')->references('id')->on('page_content_extractions')->nullOnDelete();
            $table->unique(['monitored_page_id', 'market_pack_key', 'match_type'], 'page_market_pack_matches_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_market_pack_matches');
        Schema::dropIfExists('page_brand_matches');
        Schema::dropIfExists('page_competitor_matches');
        Schema::dropIfExists('page_campaign_matches');
    }
};
