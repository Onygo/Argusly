<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            if (! Schema::hasColumn('plans', 'credit_rollover_monthly_cycles')) {
                $table->unsignedTinyInteger('credit_rollover_monthly_cycles')
                    ->nullable()
                    ->after('credit_expiry_days');
            }
        });

        DB::table('plans')
            ->where('credit_rollover_policy', 'limited')
            ->whereNull('credit_rollover_monthly_cycles')
            ->update(['credit_rollover_monthly_cycles' => 3]);

        Schema::table('workspace_credit_transactions', function (Blueprint $table): void {
            $table->index(['source', 'expires_at', 'remaining'], 'wct_source_exp_remaining_idx');
            $table->index(['reference_type', 'reference_id'], 'wct_reference_idx');
        });

        Schema::table('site_credit_allocation_buckets', function (Blueprint $table): void {
            $table->index(['source', 'expires_at', 'remaining'], 'scab_source_exp_remaining_idx');
            $table->index(['workspace_id', 'expires_at'], 'scab_workspace_exp_idx');
        });

        Schema::table('credit_pack_purchases', function (Blueprint $table): void {
            if (! Schema::hasColumn('credit_pack_purchases', 'workspace_credit_transaction_id')) {
                $table->uuid('workspace_credit_transaction_id')->nullable()->after('credit_ledger_entry_id');
            }
            if (! Schema::hasColumn('credit_pack_purchases', 'purchased_credit_expires_at')) {
                $table->timestamp('purchased_credit_expires_at')->nullable()->after('paid_at');
            }

            $table->index(['status', 'paid_at'], 'cpp_status_paid_idx');
            $table->index(['purchased_credit_expires_at'], 'cpp_expiry_idx');
            $table->index(['workspace_credit_transaction_id'], 'cpp_workspace_tx_idx');
        });
    }

    public function down(): void
    {
        Schema::table('credit_pack_purchases', function (Blueprint $table): void {
            foreach (['cpp_status_paid_idx', 'cpp_expiry_idx', 'cpp_workspace_tx_idx'] as $index) {
                try {
                    $table->dropIndex($index);
                } catch (\Throwable) {
                }
            }
        });

        Schema::table('site_credit_allocation_buckets', function (Blueprint $table): void {
            foreach (['scab_source_exp_remaining_idx', 'scab_workspace_exp_idx'] as $index) {
                try {
                    $table->dropIndex($index);
                } catch (\Throwable) {
                }
            }
        });

        Schema::table('workspace_credit_transactions', function (Blueprint $table): void {
            foreach (['wct_source_exp_remaining_idx', 'wct_reference_idx'] as $index) {
                try {
                    $table->dropIndex($index);
                } catch (\Throwable) {
                }
            }
        });

        Schema::table('plans', function (Blueprint $table): void {
            if (Schema::hasColumn('plans', 'credit_rollover_monthly_cycles')) {
                $table->dropColumn('credit_rollover_monthly_cycles');
            }
        });
    }
};
