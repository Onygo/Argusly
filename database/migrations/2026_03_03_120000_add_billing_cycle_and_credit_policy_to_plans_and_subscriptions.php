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
            if (! Schema::hasColumn('plans', 'credit_rollover_policy')) {
                $table->string('credit_rollover_policy', 32)->default('none')->after('included_credits_per_interval');
            }

            if (! Schema::hasColumn('plans', 'credit_expiry_days')) {
                $table->unsignedInteger('credit_expiry_days')->nullable()->after('credit_rollover_policy');
            }
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            if (! Schema::hasColumn('subscriptions', 'billing_cycle_anchor')) {
                $table->timestamp('billing_cycle_anchor')->nullable()->after('next_payment_at');
            }
        });

        DB::table('plans')->whereNull('credit_rollover_policy')->update(['credit_rollover_policy' => 'none']);

        DB::table('subscriptions')
            ->whereNull('billing_cycle_anchor')
            ->whereNotNull('current_period_start')
            ->update(['billing_cycle_anchor' => DB::raw('current_period_start')]);
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            if (Schema::hasColumn('subscriptions', 'billing_cycle_anchor')) {
                $table->dropColumn('billing_cycle_anchor');
            }
        });

        Schema::table('plans', function (Blueprint $table): void {
            if (Schema::hasColumn('plans', 'credit_expiry_days')) {
                $table->dropColumn('credit_expiry_days');
            }

            if (Schema::hasColumn('plans', 'credit_rollover_policy')) {
                $table->dropColumn('credit_rollover_policy');
            }
        });
    }
};
