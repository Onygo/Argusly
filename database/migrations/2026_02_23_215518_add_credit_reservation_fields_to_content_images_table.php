<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_images', function (Blueprint $table): void {
            if (! Schema::hasColumn('content_images', 'credit_wallet_id')) {
                $table->uuid('credit_wallet_id')->nullable()->after('credit_cost');
            }

            if (! Schema::hasColumn('content_images', 'credit_status')) {
                $table->string('credit_status', 32)->default('pending')->after('credit_wallet_id');
            }

            if (! Schema::hasColumn('content_images', 'credit_ledger_entry_id')) {
                $table->uuid('credit_ledger_entry_id')->nullable()->after('credit_status');
            }

            if (! Schema::hasColumn('content_images', 'credit_release_reason')) {
                $table->string('credit_release_reason', 64)->nullable()->after('credit_ledger_entry_id');
            }

            $table->index(['credit_status'], 'content_images_credit_status_idx');
            $table->index(['credit_ledger_entry_id'], 'content_images_credit_ledger_idx');
            $table->index(['credit_wallet_id'], 'content_images_credit_wallet_idx');
        });
    }

    public function down(): void
    {
        Schema::table('content_images', function (Blueprint $table): void {
            $table->dropIndex('content_images_credit_status_idx');
            $table->dropIndex('content_images_credit_ledger_idx');
            $table->dropIndex('content_images_credit_wallet_idx');

            $table->dropColumn([
                'credit_wallet_id',
                'credit_status',
                'credit_ledger_entry_id',
                'credit_release_reason',
            ]);
        });
    }
};
