<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->string('name', 220);
            $table->string('trigger', 120)->index();
            $table->json('conditions_json')->nullable();
            $table->unsignedInteger('cooldown_minutes')->default(60);
            $table->string('severity', 40)->default('medium')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_evaluated_at')->nullable();
            $table->timestamp('last_fired_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->index(['workspace_id', 'trigger', 'is_active'], 'alert_rules_workspace_trigger_active_idx');
        });

        Schema::create('page_alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->uuid('alert_rule_id')->nullable()->index();
            $table->uuid('monitored_page_id')->nullable()->index();
            $table->uuid('page_snapshot_id')->nullable()->index();
            $table->uuid('signal_event_id')->nullable()->index();
            $table->uuid('signal_detection_id')->nullable()->index();
            $table->uuid('notification_id')->nullable()->index();
            $table->uuid('recommended_action_id')->nullable()->index();
            $table->string('trigger', 120)->index();
            $table->string('severity', 40)->default('medium')->index();
            $table->string('status', 40)->default('fired')->index();
            $table->string('title', 500);
            $table->text('summary')->nullable();
            $table->char('alert_key', 64);
            $table->char('dedupe_hash', 64)->index();
            $table->json('evidence_json')->nullable();
            $table->json('metrics_json')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamp('fired_at')->index();
            $table->timestamp('dismissed_at')->nullable()->index();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('alert_rule_id')->references('id')->on('alert_rules')->nullOnDelete();
            $table->foreign('monitored_page_id')->references('id')->on('monitored_pages')->nullOnDelete();
            $table->foreign('page_snapshot_id')->references('id')->on('page_snapshots')->nullOnDelete();
            $table->foreign('signal_event_id')->references('id')->on('signal_events')->nullOnDelete();
            $table->foreign('signal_detection_id')->references('id')->on('signal_detections')->nullOnDelete();
            $table->foreign('notification_id')->references('id')->on('notifications')->nullOnDelete();
            $table->foreign('recommended_action_id')->references('id')->on('recommended_actions')->nullOnDelete();
            $table->index(['workspace_id', 'trigger', 'status', 'fired_at'], 'page_alerts_workspace_trigger_status_fired_idx');
            $table->index(['alert_rule_id', 'dedupe_hash', 'fired_at'], 'page_alerts_rule_dedupe_fired_idx');
            $table->unique(['alert_rule_id', 'alert_key'], 'page_alerts_rule_alert_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_alerts');
        Schema::dropIfExists('alert_rules');
    }
};
