<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('internal_code', 64)->nullable()->unique()->after('slug');
            $table->string('billing_provider', 32)->nullable()->after('billing_type');
            $table->string('billing_provider_plan_key', 128)->nullable()->after('billing_provider');

            $table->index(['billing_provider', 'billing_provider_plan_key'], 'plans_billing_provider_key_index');
        });

        // Populate internal_code from slug for existing plans
        \Illuminate\Support\Facades\DB::table('plans')
            ->whereNull('internal_code')
            ->update(['internal_code' => \Illuminate\Support\Facades\DB::raw('slug')]);
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropIndex('plans_billing_provider_key_index');
            $table->dropColumn(['internal_code', 'billing_provider', 'billing_provider_plan_key']);
        });
    }
};
