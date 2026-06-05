<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_intents', function (Blueprint $table) {
            if (!Schema::hasColumn('payment_intents', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('status');
            }
            if (!Schema::hasColumn('payment_intents', 'failed_at')) {
                $table->timestamp('failed_at')->nullable()->after('paid_at');
            }
            if (!Schema::hasColumn('payment_intents', 'canceled_at')) {
                $table->timestamp('canceled_at')->nullable()->after('failed_at');
            }
            if (!Schema::hasColumn('payment_intents', 'last_provider_status')) {
                $table->string('last_provider_status')->nullable()->after('provider_payment_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_intents', function (Blueprint $table) {
            if (Schema::hasColumn('payment_intents', 'paid_at')) {
                $table->dropColumn('paid_at');
            }
            if (Schema::hasColumn('payment_intents', 'failed_at')) {
                $table->dropColumn('failed_at');
            }
            if (Schema::hasColumn('payment_intents', 'canceled_at')) {
                $table->dropColumn('canceled_at');
            }
            if (Schema::hasColumn('payment_intents', 'last_provider_status')) {
                $table->dropColumn('last_provider_status');
            }
        });
    }
};
