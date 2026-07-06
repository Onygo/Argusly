<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_intelligence_report_snapshot_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->string('report_type', 80)->index();
            $table->string('market_pack_key', 120)->nullable()->index();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->char('identity_hash', 64)->unique();
            $table->unsignedInteger('current_version')->default(0);
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
        });

        Schema::table('page_intelligence_reports', function (Blueprint $table): void {
            $table->char('identity_hash', 64)->nullable()->after('report_type');
            $table->char('idempotency_key', 64)->nullable()->after('identity_hash');
            $table->string('artifact_type', 40)->nullable()->after('generated_at');
            $table->string('artifact_storage_path', 2048)->nullable()->after('artifact_type');
            $table->string('artifact_status', 40)->nullable()->after('artifact_storage_path');
            $table->timestamp('artifact_generated_at')->nullable()->after('artifact_status');
            $table->char('artifact_checksum', 64)->nullable()->after('artifact_generated_at');

            $table->unique(['identity_hash', 'snapshot_version'], 'pi_reports_identity_snapshot_unique');
            $table->unique('idempotency_key', 'pi_reports_idempotency_key_unique');
            $table->index(['artifact_status', 'artifact_generated_at'], 'pi_reports_artifact_status_generated_idx');
        });
    }

    public function down(): void
    {
        Schema::table('page_intelligence_reports', function (Blueprint $table): void {
            $table->dropUnique('pi_reports_identity_snapshot_unique');
            $table->dropUnique('pi_reports_idempotency_key_unique');
            $table->dropIndex('pi_reports_artifact_status_generated_idx');
            $table->dropColumn([
                'identity_hash',
                'idempotency_key',
                'artifact_type',
                'artifact_storage_path',
                'artifact_status',
                'artifact_generated_at',
                'artifact_checksum',
            ]);
        });

        Schema::dropIfExists('page_intelligence_report_snapshot_allocations');
    }
};
