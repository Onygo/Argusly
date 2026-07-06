<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_intelligence_report_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('report_id')->index();
            $table->uuid('scheduled_briefing_id')->nullable()->index('pi_report_deliveries_schedule_idx');
            $table->uuid('workspace_id')->index();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_email')->nullable()->index();
            $table->string('channel', 40)->index();
            $table->string('status', 40)->default('pending')->index();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('report_id')
                ->references('id')
                ->on('page_intelligence_reports')
                ->cascadeOnDelete();
            $table->foreign('scheduled_briefing_id', 'pi_report_deliveries_schedule_fk')
                ->references('id')
                ->on('scheduled_page_intelligence_briefings')
                ->nullOnDelete();
            $table->foreign('workspace_id')
                ->references('id')
                ->on('workspaces')
                ->cascadeOnDelete();

            $table->unique(['report_id', 'channel', 'recipient_user_id'], 'pi_deliveries_report_channel_user_unique');
            $table->unique(['report_id', 'channel', 'recipient_email'], 'pi_deliveries_report_channel_email_unique');
            $table->index(['workspace_id', 'status', 'created_at'], 'pi_deliveries_workspace_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_intelligence_report_deliveries');
    }
};
