<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->uuid('credit_wallet_id')->nullable()->after('client_site_id');

            $table->index(['credit_wallet_id']);

            $table->foreign('credit_wallet_id')
                ->references('id')
                ->on('credit_wallets')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->dropForeign(['credit_wallet_id']);
            $table->dropIndex(['credit_wallet_id']);
            $table->dropColumn('credit_wallet_id');
        });
    }
};
