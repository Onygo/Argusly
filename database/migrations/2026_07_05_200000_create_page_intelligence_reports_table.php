<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_intelligence_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('market_pack_id')->nullable()->index();
            $table->string('market_pack_key', 120)->nullable()->index();
            $table->string('report_type', 80)->index();
            $table->string('title', 240);
            $table->string('status', 40)->default('generated')->index();
            $table->unsignedInteger('snapshot_version')->default(1);
            $table->string('template_version', 80)->default('page-intelligence-report-v1');
            $table->timestamp('period_start')->nullable()->index();
            $table->timestamp('period_end')->nullable()->index();
            $table->text('summary')->nullable();
            $table->json('payload_json');
            $table->json('provenance_json')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('market_pack_id')->references('id')->on('market_packs')->nullOnDelete();
            $table->index(['workspace_id', 'report_type', 'generated_at'], 'pi_reports_workspace_type_generated_idx');
            $table->index(['workspace_id', 'market_pack_key', 'generated_at'], 'pi_reports_workspace_pack_generated_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_intelligence_reports');
    }
};
