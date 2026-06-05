<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agentic_marketing_actions')) {
            return;
        }

        $hadReservationColumn = Schema::hasColumn('agentic_marketing_actions', 'credit_reservation_id');

        Schema::table('agentic_marketing_actions', function (Blueprint $table) use ($hadReservationColumn): void {
            if (! $hadReservationColumn) {
                $table->uuid('credit_reservation_id')->nullable()->after('estimated_credits')->index();
            }

            if (! Schema::hasColumn('agentic_marketing_actions', 'credits_reserved')) {
                $table->unsignedInteger('credits_reserved')->nullable()->after('credit_reservation_id');
            }

            if (! Schema::hasColumn('agentic_marketing_actions', 'credits_captured')) {
                $table->unsignedInteger('credits_captured')->nullable()->after('credits_reserved');
            }

            if (! Schema::hasColumn('agentic_marketing_actions', 'credit_status')) {
                $table->string('credit_status', 32)->default('unreserved')->after('credits_captured')->index();
            }

            if (! Schema::hasColumn('agentic_marketing_actions', 'credit_error_message')) {
                $table->text('credit_error_message')->nullable()->after('credit_status');
            }

            if (! Schema::hasColumn('agentic_marketing_actions', 'budget_checked_at')) {
                $table->timestamp('budget_checked_at')->nullable()->after('credit_error_message');
            }

            if (! Schema::hasColumn('agentic_marketing_actions', 'budget_exceeded_at')) {
                $table->timestamp('budget_exceeded_at')->nullable()->after('budget_checked_at');
            }
        });

        if (
            Schema::hasColumn('agentic_marketing_actions', 'credit_reservation_id')
            && Schema::hasTable('credit_reservations')
            && ! $hadReservationColumn
        ) {
            Schema::table('agentic_marketing_actions', function (Blueprint $table): void {
                $table->foreign('credit_reservation_id', 'agentic_actions_credit_reservation_fk')
                    ->references('id')
                    ->on('credit_reservations')
                    ->nullOnDelete();
            });
        }

        DB::table('agentic_marketing_actions')
            ->whereNull('credit_status')
            ->update(['credit_status' => 'unreserved']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('agentic_marketing_actions')) {
            return;
        }

        Schema::table('agentic_marketing_actions', function (Blueprint $table): void {
            if (Schema::hasIndex('agentic_marketing_actions', 'agentic_actions_credit_reservation_fk')) {
                $table->dropForeign('agentic_actions_credit_reservation_fk');
            }

            foreach (['credit_reservation_id', 'credits_reserved', 'credits_captured', 'credit_status', 'credit_error_message', 'budget_checked_at', 'budget_exceeded_at'] as $column) {
                if (Schema::hasColumn('agentic_marketing_actions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
