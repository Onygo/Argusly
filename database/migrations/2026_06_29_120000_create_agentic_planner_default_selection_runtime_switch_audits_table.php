<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agentic_planner_default_selection_runtime_switch_audits')) {
            Schema::create('agentic_planner_default_selection_runtime_switch_audits', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->nullable()->index('apds_runtime_switch_workspace_idx');
                $table->json('objective_ids')->nullable();
                $table->string('phase_3t_status', 96)->nullable();
                $table->string('phase_3u_eligibility', 96)->nullable();
                $table->boolean('phase_3v_guard_allowed')->default(false);
                $table->string('phase_3w_selected_planner_remains', 64)->nullable();
                $table->string('phase_3x_contract_status', 96)->nullable();
                $table->boolean('switch_flag_enabled')->default(false);
                $table->boolean('runtime_guard_flag_enabled')->default(false);
                $table->string('switch_decision', 64)->index('apds_runtime_switch_decision_idx');
                $table->json('blocked_reasons')->nullable();
                $table->json('operator_acknowledgements')->nullable();
                $table->string('rollback_mode', 64)->nullable();
                $table->string('selected_planner', 64)->nullable();
                $table->string('selected_action_ownership_mode', 96)->nullable();
                $table->string('payload_namespace', 128);
                $table->string('payload_version', 64);
                $table->json('payload')->nullable();
                $table->timestamp('created_at')->useCurrent()->index('apds_runtime_switch_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agentic_planner_default_selection_runtime_switch_audits');
    }
};
