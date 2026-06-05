<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->upgradeObjectives();
        $this->upgradeActions();
    }

    public function down(): void
    {
        // Intentional no-op. This migration reconciles databases that already
        // ran an older version of the Agentic Marketing table migration.
    }

    private function upgradeObjectives(): void
    {
        if (! Schema::hasTable('agentic_marketing_objectives')) {
            return;
        }

        Schema::table('agentic_marketing_objectives', function (Blueprint $table): void {
            if (! Schema::hasColumn('agentic_marketing_objectives', 'client_site_id')) {
                $table->uuid('client_site_id')->nullable();
            }

            if (! Schema::hasColumn('agentic_marketing_objectives', 'name')) {
                $table->string('name')->nullable();
            }

            if (! Schema::hasColumn('agentic_marketing_objectives', 'locale')) {
                $table->string('locale', 12)->default('en');
            }

            if (! Schema::hasColumn('agentic_marketing_objectives', 'audience')) {
                $table->text('audience')->nullable();
            }

            if (! Schema::hasColumn('agentic_marketing_objectives', 'monthly_credit_budget')) {
                $table->unsignedInteger('monthly_credit_budget')->nullable();
            }
        });

        $this->backfillObjectives();

        Schema::table('agentic_marketing_objectives', function (Blueprint $table): void {
            if (Schema::hasColumn('agentic_marketing_objectives', 'client_site_id')
                && ! Schema::hasIndex('agentic_marketing_objectives', 'agentic_marketing_objectives_client_site_id_index')) {
                $table->index('client_site_id');
            }

            if (Schema::hasColumn('agentic_marketing_objectives', 'organization_id')
                && Schema::hasColumn('agentic_marketing_objectives', 'status')
                && ! Schema::hasIndex('agentic_marketing_objectives', 'agentic_objectives_org_status_idx')) {
                $table->index(['organization_id', 'status'], 'agentic_objectives_org_status_idx');
            }
        });
    }

    private function upgradeActions(): void
    {
        if (! Schema::hasTable('agentic_marketing_actions')) {
            return;
        }

        Schema::table('agentic_marketing_actions', function (Blueprint $table): void {
            if (! Schema::hasColumn('agentic_marketing_actions', 'execution_claim_id')) {
                $table->uuid('execution_claim_id')->nullable();
            }

            if (! Schema::hasColumn('agentic_marketing_actions', 'execution_claimed_at')) {
                $table->timestamp('execution_claimed_at')->nullable();
            }
        });

        Schema::table('agentic_marketing_actions', function (Blueprint $table): void {
            if (Schema::hasColumn('agentic_marketing_actions', 'execution_claim_id')
                && ! Schema::hasIndex('agentic_marketing_actions', 'agentic_marketing_actions_execution_claim_id_index')) {
                $table->index('execution_claim_id');
            }
        });
    }

    private function backfillObjectives(): void
    {
        if (Schema::hasColumn('agentic_marketing_objectives', 'name')) {
            DB::table('agentic_marketing_objectives')
                ->whereNull('name')
                ->orWhere('name', '')
                ->update([
                    'name' => DB::raw("COALESCE(NULLIF(goal, ''), 'Agentic Marketing Objective')"),
                ]);
        }

        if (Schema::hasColumn('agentic_marketing_objectives', 'locale')) {
            DB::table('agentic_marketing_objectives')
                ->whereNull('locale')
                ->orWhere('locale', '')
                ->update(['locale' => 'en']);
        }

        if (Schema::hasColumn('agentic_marketing_objectives', 'approval_mode')) {
            DB::table('agentic_marketing_objectives')
                ->whereNull('approval_mode')
                ->orWhere('approval_mode', '')
                ->update(['approval_mode' => 'manual']);
        }

        if (Schema::hasColumn('agentic_marketing_objectives', 'status')) {
            DB::table('agentic_marketing_objectives')
                ->whereNull('status')
                ->orWhere('status', '')
                ->update(['status' => 'active']);
        }
    }
};
