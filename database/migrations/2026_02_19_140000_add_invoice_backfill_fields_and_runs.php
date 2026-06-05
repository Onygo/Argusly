<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'is_backfilled')) {
                $table->boolean('is_backfilled')->default(false)->after('meta');
            }
            if (! Schema::hasColumn('invoices', 'backfilled_at')) {
                $table->timestamp('backfilled_at')->nullable()->after('is_backfilled');
            }
            if (! Schema::hasColumn('invoices', 'backfill_source')) {
                $table->string('backfill_source', 64)->nullable()->after('backfilled_at');
            }
            if (! Schema::hasColumn('invoices', 'backfill_batch_id')) {
                $table->uuid('backfill_batch_id')->nullable()->after('backfill_source');
            }
            if (! Schema::hasColumn('invoices', 'pdf_status')) {
                $table->string('pdf_status', 32)->nullable()->after('pdf_checksum');
            }
            if (! Schema::hasColumn('invoices', 'pdf_error_message')) {
                $table->string('pdf_error_message', 512)->nullable()->after('pdf_status');
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            $indexes = [
                'invoices_backfill_batch_idx' => ['backfill_batch_id'],
                'invoices_backfilled_at_idx' => ['backfilled_at'],
                'invoices_pdf_status_idx' => ['pdf_status'],
            ];

            foreach ($indexes as $name => $cols) {
                if (! $this->indexExists('invoices', $name)) {
                    $table->index($cols, $name);
                }
            }
        });

        if (! Schema::hasTable('invoice_backfill_runs')) {
            Schema::create('invoice_backfill_runs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('batch_id')->unique();
                $table->boolean('dry_run')->default(false);
                $table->date('from_date')->nullable();
                $table->date('to_date')->nullable();
                $table->unsignedBigInteger('organization_id')->nullable();
                $table->unsignedInteger('limit_count')->default(500);
                $table->boolean('queue_pdf')->default(false);
                $table->json('summary')->nullable();
                $table->string('report_path')->nullable();
                $table->timestamp('started_at');
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index(['organization_id', 'started_at'], 'ibr_org_started_idx');
                $table->index(['started_at'], 'ibr_started_idx');
                $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('invoice_backfill_run_items')) {
            Schema::create('invoice_backfill_run_items', function (Blueprint $table) {
                $table->id();
                $table->uuid('run_id');
                $table->uuid('payment_intent_id')->nullable();
                $table->string('provider_payment_id', 128)->nullable();
                $table->unsignedBigInteger('organization_id')->nullable();
                $table->string('type', 64)->nullable();
                $table->string('result', 32); // created | skipped | failed
                $table->string('reason', 128)->nullable();
                $table->uuid('invoice_id')->nullable();
                $table->string('pdf_status', 32)->nullable();
                $table->text('error')->nullable();
                $table->timestamps();

                $table->index(['run_id', 'result'], 'ibri_run_result_idx');
                $table->unique(['run_id', 'payment_intent_id'], 'ibri_run_payment_unique');
                $table->foreign('run_id')->references('id')->on('invoice_backfill_runs')->cascadeOnDelete();
                $table->foreign('invoice_id')->references('id')->on('invoices')->nullOnDelete();
                $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_backfill_run_items');
        Schema::dropIfExists('invoice_backfill_runs');

        Schema::table('invoices', function (Blueprint $table) {
            foreach (['invoices_backfill_batch_idx', 'invoices_backfilled_at_idx', 'invoices_pdf_status_idx'] as $indexName) {
                if ($this->indexExists('invoices', $indexName)) {
                    $table->dropIndex($indexName);
                }
            }

            foreach (['is_backfilled', 'backfilled_at', 'backfill_source', 'backfill_batch_id', 'pdf_status', 'pdf_error_message'] as $column) {
                if (Schema::hasColumn('invoices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            $database = DB::getDatabaseName();
            $row = DB::selectOne(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
                [$database, $table, $indexName]
            );

            return $row !== null;
        }

        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('{$table}')");
            foreach ($rows as $row) {
                if (($row->name ?? null) === $indexName) {
                    return true;
                }
            }
        }

        return false;
    }
};
