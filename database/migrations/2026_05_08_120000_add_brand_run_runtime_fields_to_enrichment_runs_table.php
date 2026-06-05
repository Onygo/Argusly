<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrichment_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('enrichment_runs', 'failure_reason')) {
                $table->string('failure_reason', 120)->nullable()->after('error_message');
            }

            if (! Schema::hasColumn('enrichment_runs', 'diagnostic_payload')) {
                $table->json('diagnostic_payload')->nullable()->after('failure_reason');
            }

            if (! Schema::hasColumn('enrichment_runs', 'queued_at')) {
                $table->timestamp('queued_at')->nullable()->after('approved_at');
            }

            if (! Schema::hasColumn('enrichment_runs', 'started_at')) {
                $table->timestamp('started_at')->nullable()->after('queued_at');
            }

            if (! Schema::hasColumn('enrichment_runs', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('started_at');
            }

            if (! Schema::hasColumn('enrichment_runs', 'failed_at')) {
                $table->timestamp('failed_at')->nullable()->after('completed_at');
            }

            if (! Schema::hasColumn('enrichment_runs', 'last_heartbeat_at')) {
                $table->timestamp('last_heartbeat_at')->nullable()->after('failed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('enrichment_runs', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('enrichment_runs', 'failure_reason') ? 'failure_reason' : null,
                Schema::hasColumn('enrichment_runs', 'diagnostic_payload') ? 'diagnostic_payload' : null,
                Schema::hasColumn('enrichment_runs', 'queued_at') ? 'queued_at' : null,
                Schema::hasColumn('enrichment_runs', 'started_at') ? 'started_at' : null,
                Schema::hasColumn('enrichment_runs', 'completed_at') ? 'completed_at' : null,
                Schema::hasColumn('enrichment_runs', 'failed_at') ? 'failed_at' : null,
                Schema::hasColumn('enrichment_runs', 'last_heartbeat_at') ? 'last_heartbeat_at' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
