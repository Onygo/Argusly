<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_page_intelligence_briefings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->string('report_type', 80)->index();
            $table->string('market_pack_key', 120)->nullable()->index();
            $table->string('frequency', 20)->index();
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->string('timezone', 80)->default('UTC');
            $table->json('recipients_json')->nullable();
            $table->json('delivery_channels_json')->nullable();
            $table->json('delivery_state_json')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_generated_at')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->index(['is_active', 'next_run_at'], 'scheduled_pi_briefings_due_idx');
        });

        Schema::table('page_intelligence_reports', function (Blueprint $table): void {
            $table->uuid('scheduled_page_intelligence_briefing_id')->nullable()->after('artifact_checksum')->index('pi_reports_scheduled_briefing_idx');
            $table->foreign('scheduled_page_intelligence_briefing_id', 'pi_reports_scheduled_briefing_fk')
                ->references('id')
                ->on('scheduled_page_intelligence_briefings')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('page_intelligence_reports', function (Blueprint $table): void {
            $table->dropForeign('pi_reports_scheduled_briefing_fk');
            $table->dropIndex('pi_reports_scheduled_briefing_idx');
            $table->dropColumn('scheduled_page_intelligence_briefing_id');
        });

        Schema::dropIfExists('scheduled_page_intelligence_briefings');
    }
};
