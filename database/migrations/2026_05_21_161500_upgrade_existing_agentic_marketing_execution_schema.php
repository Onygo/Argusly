<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->upgradePipelines();
        $this->upgradeAssets();
        $this->upgradeApprovals();
        $this->upgradeFeedback();
        $this->upgradeAuditLogs();
    }

    public function down(): void
    {
        // Intentional no-op. This migration reconciles live databases that
        // already ran an earlier execution pipeline schema.
    }

    private function upgradePipelines(): void
    {
        if (! Schema::hasTable('agentic_marketing_execution_pipelines')) {
            return;
        }

        Schema::table('agentic_marketing_execution_pipelines', function (Blueprint $table): void {
            if (! Schema::hasColumn('agentic_marketing_execution_pipelines', 'organization_id')) {
                $table->foreignId('organization_id')->nullable()->after('id')->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('agentic_marketing_execution_pipelines', 'run_id')) {
                $table->uuid('run_id')->nullable()->after('opportunity_id');
            }
            if (! Schema::hasColumn('agentic_marketing_execution_pipelines', 'mode')) {
                $table->string('mode', 40)->default('manual')->after('run_id');
            }
            if (! Schema::hasColumn('agentic_marketing_execution_pipelines', 'current_stage')) {
                $table->string('current_stage', 80)->default('queued')->after('status');
            }
            if (! Schema::hasColumn('agentic_marketing_execution_pipelines', 'approval_status')) {
                $table->string('approval_status', 40)->default('pending')->after('current_stage')->index();
            }
            if (! Schema::hasColumn('agentic_marketing_execution_pipelines', 'publishing_readiness')) {
                $table->string('publishing_readiness', 40)->default('not_ready')->after('approval_status')->index();
            }
            if (! Schema::hasColumn('agentic_marketing_execution_pipelines', 'assets_count')) {
                $table->unsignedInteger('assets_count')->default(0)->after('publishing_readiness');
            }
            if (! Schema::hasColumn('agentic_marketing_execution_pipelines', 'pending_approvals_count')) {
                $table->unsignedInteger('pending_approvals_count')->default(0)->after('assets_count');
            }
            if (! Schema::hasColumn('agentic_marketing_execution_pipelines', 'input')) {
                $table->json('input')->nullable()->after('pending_approvals_count');
            }
            if (! Schema::hasColumn('agentic_marketing_execution_pipelines', 'result')) {
                $table->json('result')->nullable()->after('input');
            }
            if (! Schema::hasColumn('agentic_marketing_execution_pipelines', 'rollback_snapshot')) {
                $table->json('rollback_snapshot')->nullable()->after('result');
            }
            if (! Schema::hasColumn('agentic_marketing_execution_pipelines', 'failure_reason')) {
                $table->text('failure_reason')->nullable()->after('rollback_snapshot');
            }
            if (! Schema::hasColumn('agentic_marketing_execution_pipelines', 'requested_by')) {
                $table->unsignedBigInteger('requested_by')->nullable()->after('failure_reason');
            }
            if (! Schema::hasColumn('agentic_marketing_execution_pipelines', 'started_at')) {
                $table->timestamp('started_at')->nullable()->after('requested_by');
            }
            if (! Schema::hasColumn('agentic_marketing_execution_pipelines', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('started_at');
            }
            if (! Schema::hasColumn('agentic_marketing_execution_pipelines', 'failed_at')) {
                $table->timestamp('failed_at')->nullable()->after('completed_at');
            }
        });
    }

    private function upgradeAssets(): void
    {
        if (! Schema::hasTable('agentic_marketing_execution_assets')) {
            return;
        }

        Schema::table('agentic_marketing_execution_assets', function (Blueprint $table): void {
            if (! Schema::hasColumn('agentic_marketing_execution_assets', 'payload')) {
                $table->json('payload')->nullable()->after('title');
            }
            if (! Schema::hasColumn('agentic_marketing_execution_assets', 'assetable_type')) {
                $table->string('assetable_type')->nullable()->after('payload');
            }
            if (! Schema::hasColumn('agentic_marketing_execution_assets', 'assetable_id')) {
                $table->string('assetable_id')->nullable()->after('assetable_type');
            }
            if (! Schema::hasColumn('agentic_marketing_execution_assets', 'requires_approval')) {
                $table->boolean('requires_approval')->default(true)->after('assetable_id');
            }
            if (! Schema::hasColumn('agentic_marketing_execution_assets', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('requires_approval');
            }
            if (! Schema::hasColumn('agentic_marketing_execution_assets', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }
            if (! Schema::hasColumn('agentic_marketing_execution_assets', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('approved_at');
            }
        });
    }

    private function upgradeApprovals(): void
    {
        if (! Schema::hasTable('agentic_marketing_execution_approvals')) {
            return;
        }

        Schema::table('agentic_marketing_execution_approvals', function (Blueprint $table): void {
            if (! Schema::hasColumn('agentic_marketing_execution_approvals', 'asset_id')) {
                $table->uuid('asset_id')->nullable()->after('pipeline_id');
            }
            if (! Schema::hasColumn('agentic_marketing_execution_approvals', 'approval_type')) {
                $table->string('approval_type', 80)->default('editorial_review')->after('status');
            }
            if (! Schema::hasColumn('agentic_marketing_execution_approvals', 'requested_role')) {
                $table->string('requested_role', 80)->default('editor')->after('approval_type');
            }
            if (! Schema::hasColumn('agentic_marketing_execution_approvals', 'requested_by')) {
                $table->unsignedBigInteger('requested_by')->nullable()->after('requested_role');
            }
            if (! Schema::hasColumn('agentic_marketing_execution_approvals', 'reviewed_by')) {
                $table->unsignedBigInteger('reviewed_by')->nullable()->after('requested_by');
            }
            if (! Schema::hasColumn('agentic_marketing_execution_approvals', 'feedback')) {
                $table->text('feedback')->nullable()->after('reviewed_by');
            }
            if (! Schema::hasColumn('agentic_marketing_execution_approvals', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('feedback');
            }
        });
    }

    private function upgradeFeedback(): void
    {
        if (! Schema::hasTable('agentic_marketing_execution_feedback')) {
            return;
        }

        Schema::table('agentic_marketing_execution_feedback', function (Blueprint $table): void {
            if (! Schema::hasColumn('agentic_marketing_execution_feedback', 'asset_id')) {
                $table->uuid('asset_id')->nullable()->after('pipeline_id');
            }
            if (! Schema::hasColumn('agentic_marketing_execution_feedback', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('asset_id');
            }
            if (! Schema::hasColumn('agentic_marketing_execution_feedback', 'type')) {
                $table->string('type', 80)->default('review_note')->after('user_id');
            }
            if (! Schema::hasColumn('agentic_marketing_execution_feedback', 'payload')) {
                $table->json('payload')->nullable()->after('body');
            }
        });
    }

    private function upgradeAuditLogs(): void
    {
        if (! Schema::hasTable('agentic_marketing_execution_audit_logs')) {
            return;
        }

        Schema::table('agentic_marketing_execution_audit_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('agentic_marketing_execution_audit_logs', 'asset_id')) {
                $table->uuid('asset_id')->nullable()->after('pipeline_id');
            }
            if (! Schema::hasColumn('agentic_marketing_execution_audit_logs', 'actor_id')) {
                $table->unsignedBigInteger('actor_id')->nullable()->after('asset_id');
            }
            if (! Schema::hasColumn('agentic_marketing_execution_audit_logs', 'before')) {
                $table->json('before')->nullable()->after('event');
            }
            if (! Schema::hasColumn('agentic_marketing_execution_audit_logs', 'after')) {
                $table->json('after')->nullable()->after('before');
            }
            if (! Schema::hasColumn('agentic_marketing_execution_audit_logs', 'metadata')) {
                $table->json('metadata')->nullable()->after('after');
            }
        });
    }
};
