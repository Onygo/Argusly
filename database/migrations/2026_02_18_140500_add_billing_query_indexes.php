<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_ledger_entries', function (Blueprint $table) {
            $table->index(['client_site_id', 'created_at'], 'cle_site_created_idx');
            $table->index(['type', 'created_at'], 'cle_type_created_idx');
            $table->index('created_at', 'cle_created_idx');
        });

        Schema::table('payment_intents', function (Blueprint $table) {
            $table->index(['billable_type', 'billable_id', 'created_at'], 'pi_billable_created_idx');
            $table->index(['status', 'created_at'], 'pi_status_created_idx');
            $table->index(['provider', 'status', 'created_at'], 'pi_provider_status_created_idx');
        });

        Schema::table('credit_pack_purchases', function (Blueprint $table) {
            $table->index(['client_site_id', 'created_at'], 'cpp_site_created_idx');
            $table->index(['status', 'created_at'], 'cpp_status_created_idx');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'subs_status_created_idx');
            $table->index('current_period_end', 'subs_period_end_idx');
        });
    }

    public function down(): void
    {
        Schema::table('credit_ledger_entries', function (Blueprint $table) {
            $table->dropIndex('cle_site_created_idx');
            $table->dropIndex('cle_type_created_idx');
            $table->dropIndex('cle_created_idx');
        });

        Schema::table('payment_intents', function (Blueprint $table) {
            $table->dropIndex('pi_billable_created_idx');
            $table->dropIndex('pi_status_created_idx');
            $table->dropIndex('pi_provider_status_created_idx');
        });

        Schema::table('credit_pack_purchases', function (Blueprint $table) {
            $table->dropIndex('cpp_site_created_idx');
            $table->dropIndex('cpp_status_created_idx');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex('subs_status_created_idx');
            $table->dropIndex('subs_period_end_idx');
        });
    }
};
