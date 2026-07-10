<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connector_normalization_runs', function (Blueprint $table): void {
            $table->string('source_type', 40)->nullable()->index();
            $table->string('source_key', 191)->nullable()->index();
            $table->date('scope_start_date')->nullable()->index();
            $table->date('scope_end_date')->nullable()->index();
            $table->char('scope_hash', 64)->nullable()->index();
            $table->char('active_scope_hash', 64)->nullable();

            $table->unique('active_scope_hash', 'cn_runs_active_scope_unique');
            $table->index([
                'workspace_id',
                'connector_account_id',
                'provider',
                'dataset_key',
                'source_type',
                'scope_start_date',
                'scope_end_date',
            ], 'cn_runs_scope_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('connector_normalization_runs', function (Blueprint $table): void {
            $table->dropUnique('cn_runs_active_scope_unique');
            $table->dropIndex('cn_runs_scope_lookup_idx');
            $table->dropColumn([
                'source_type',
                'source_key',
                'scope_start_date',
                'scope_end_date',
                'scope_hash',
                'active_scope_hash',
            ]);
        });
    }
};
