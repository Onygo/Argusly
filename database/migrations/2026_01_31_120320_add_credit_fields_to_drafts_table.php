<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->uuid('credit_action_id')->nullable()->after('output_type');
            $table->unsignedInteger('credit_cost')->nullable()->after('credit_action_id');

            $table->string('credit_status', 32)->default('pending')->after('credit_cost');
            $table->uuid('credit_ledger_entry_id')->nullable()->after('credit_status');

            $table->index(['credit_action_id']);
            $table->index(['credit_status']);
            $table->index(['credit_ledger_entry_id']);

            $table->foreign('credit_action_id')
                ->references('id')
                ->on('credit_actions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->dropForeign(['credit_action_id']);

            $table->dropIndex(['credit_action_id']);
            $table->dropIndex(['credit_status']);
            $table->dropIndex(['credit_ledger_entry_id']);

            $table->dropColumn([
                'credit_action_id',
                'credit_cost',
                'credit_status',
                'credit_ledger_entry_id',
            ]);
        });
    }
};
