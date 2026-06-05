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
            if (! Schema::hasColumn('invoices', 'pricing_mode')) {
                $table->string('pricing_mode', 32)->default('vat_inclusive')->after('currency');
            }
            if (! Schema::hasColumn('invoices', 'subtotal_net')) {
                $table->decimal('subtotal_net', 12, 2)->nullable()->after('pricing_mode');
            }
            if (! Schema::hasColumn('invoices', 'vat_amount')) {
                $table->decimal('vat_amount', 12, 2)->nullable()->after('subtotal_net');
            }
            if (! Schema::hasColumn('invoices', 'total_gross')) {
                $table->decimal('total_gross', 12, 2)->nullable()->after('vat_amount');
            }
            if (! Schema::hasColumn('invoices', 'document_type')) {
                $table->string('document_type', 32)->default('invoice')->after('type');
            }
            if (! Schema::hasColumn('invoices', 'corrected_at')) {
                $table->timestamp('corrected_at')->nullable()->after('backfill_batch_id');
            }
            if (! Schema::hasColumn('invoices', 'correction_reason')) {
                $table->text('correction_reason')->nullable()->after('corrected_at');
            }
            if (! Schema::hasColumn('invoices', 'corrected_by_batch_id')) {
                $table->uuid('corrected_by_batch_id')->nullable()->after('correction_reason');
            }
            if (! Schema::hasColumn('invoices', 'replaces_invoice_id')) {
                $table->uuid('replaces_invoice_id')->nullable()->after('corrected_by_batch_id');
            }
            if (! Schema::hasColumn('invoices', 'credit_note_for_invoice_id')) {
                $table->uuid('credit_note_for_invoice_id')->nullable()->after('replaces_invoice_id');
            }
            if (! Schema::hasColumn('invoices', 'pdf_path_previous')) {
                $table->string('pdf_path_previous')->nullable()->after('pdf_path');
            }
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            if (! Schema::hasColumn('invoice_items', 'unit_price_net')) {
                $table->decimal('unit_price_net', 12, 2)->nullable()->after('unit_price_cents');
            }
            if (! Schema::hasColumn('invoice_items', 'line_total_net')) {
                $table->decimal('line_total_net', 12, 2)->nullable()->after('subtotal_cents');
            }
            if (! Schema::hasColumn('invoice_items', 'vat_amount')) {
                $table->decimal('vat_amount', 12, 2)->nullable()->after('tax_cents');
            }
            if (! Schema::hasColumn('invoice_items', 'line_total_gross')) {
                $table->decimal('line_total_gross', 12, 2)->nullable()->after('total_cents');
            }
        });

        if (! $this->indexExists('invoices', 'invoices_doc_type_idx')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->index(['document_type', 'issued_at'], 'invoices_doc_type_idx');
            });
        }
        if (! $this->indexExists('invoices', 'invoices_corrected_batch_idx')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->index(['corrected_by_batch_id'], 'invoices_corrected_batch_idx');
            });
        }
        if (! $this->indexExists('invoices', 'invoices_replaces_idx')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->index(['replaces_invoice_id'], 'invoices_replaces_idx');
            });
        }
        if (! $this->indexExists('invoices', 'invoices_credit_note_for_idx')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->index(['credit_note_for_invoice_id'], 'invoices_credit_note_for_idx');
            });
        }

        if (! $this->foreignKeyExists('invoices', 'invoices_replaces_invoice_id_foreign')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->foreign('replaces_invoice_id')->references('id')->on('invoices')->nullOnDelete();
            });
        }
        if (! $this->foreignKeyExists('invoices', 'invoices_credit_note_for_invoice_id_foreign')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->foreign('credit_note_for_invoice_id')->references('id')->on('invoices')->nullOnDelete();
            });
        }

        DB::table('invoices')->update([
            'pricing_mode' => DB::raw("COALESCE(pricing_mode, 'vat_inclusive')"),
            'subtotal_net' => DB::raw('COALESCE(subtotal_net, subtotal_cents / 100)'),
            'vat_amount' => DB::raw('COALESCE(vat_amount, tax_cents / 100)'),
            'total_gross' => DB::raw('COALESCE(total_gross, total_cents / 100)'),
            'document_type' => DB::raw("COALESCE(document_type, 'invoice')"),
        ]);

        DB::table('invoice_items')->update([
            'unit_price_net' => DB::raw('COALESCE(unit_price_net, unit_price_cents / 100)'),
            'line_total_net' => DB::raw('COALESCE(line_total_net, subtotal_cents / 100)'),
            'vat_amount' => DB::raw('COALESCE(vat_amount, tax_cents / 100)'),
            'line_total_gross' => DB::raw('COALESCE(line_total_gross, total_cents / 100)'),
        ]);
    }

    public function down(): void
    {
        if ($this->foreignKeyExists('invoices', 'invoices_replaces_invoice_id_foreign')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropForeign(['replaces_invoice_id']);
            });
        }
        if ($this->foreignKeyExists('invoices', 'invoices_credit_note_for_invoice_id_foreign')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropForeign(['credit_note_for_invoice_id']);
            });
        }

        foreach ([
            'invoices_doc_type_idx',
            'invoices_corrected_batch_idx',
            'invoices_replaces_idx',
            'invoices_credit_note_for_idx',
        ] as $indexName) {
            if ($this->indexExists('invoices', $indexName)) {
                Schema::table('invoices', function (Blueprint $table) use ($indexName) {
                    $table->dropIndex($indexName);
                });
            }
        }

        Schema::table('invoice_items', function (Blueprint $table) {
            foreach (['unit_price_net', 'line_total_net', 'vat_amount', 'line_total_gross'] as $column) {
                if (Schema::hasColumn('invoice_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            foreach ([
                'pricing_mode',
                'subtotal_net',
                'vat_amount',
                'total_gross',
                'document_type',
                'corrected_at',
                'correction_reason',
                'corrected_by_batch_id',
                'replaces_invoice_id',
                'credit_note_for_invoice_id',
                'pdf_path_previous',
            ] as $column) {
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

    private function foreignKeyExists(string $table, string $constraintName): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            $database = DB::getDatabaseName();
            $row = DB::selectOne(
                'SELECT 1 FROM information_schema.table_constraints WHERE table_schema = ? AND table_name = ? AND constraint_name = ? AND constraint_type = ? LIMIT 1',
                [$database, $table, $constraintName, 'FOREIGN KEY']
            );

            return $row !== null;
        }

        return false;
    }
};

