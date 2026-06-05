<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('agent_runs', 'workflow_run_id')) {
                $table->uuid('workflow_run_id')->nullable()->after('user_id');
            }

            if (! Schema::hasColumn('agent_runs', 'workflow_step_key')) {
                $table->string('workflow_step_key')->nullable()->after('workflow_run_id');
            }

            $table->index(['workflow_run_id', 'created_at'], 'agent_runs_workflow_created_idx');
            $table->index(['workflow_step_key', 'created_at'], 'agent_runs_step_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table): void {
            if (Schema::hasColumn('agent_runs', 'workflow_run_id')) {
                $table->dropIndex('agent_runs_workflow_created_idx');
                $table->dropColumn('workflow_run_id');
            }

            if (Schema::hasColumn('agent_runs', 'workflow_step_key')) {
                $table->dropIndex('agent_runs_step_created_idx');
                $table->dropColumn('workflow_step_key');
            }
        });
    }
};
