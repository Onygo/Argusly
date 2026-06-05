<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_pack_purchases', function (Blueprint $table) {
            if (!Schema::hasColumn('credit_pack_purchases', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('status');
            }
            if (!Schema::hasColumn('credit_pack_purchases', 'failed_at')) {
                $table->timestamp('failed_at')->nullable()->after('paid_at');
            }
            if (!Schema::hasColumn('credit_pack_purchases', 'canceled_at')) {
                $table->timestamp('canceled_at')->nullable()->after('failed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('credit_pack_purchases', function (Blueprint $table) {
            if (Schema::hasColumn('credit_pack_purchases', 'paid_at')) {
                $table->dropColumn('paid_at');
            }
            if (Schema::hasColumn('credit_pack_purchases', 'failed_at')) {
                $table->dropColumn('failed_at');
            }
            if (Schema::hasColumn('credit_pack_purchases', 'canceled_at')) {
                $table->dropColumn('canceled_at');
            }
        });
    }
};
