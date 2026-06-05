<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('draft_analyses', function (Blueprint $table): void {
            if (! Schema::hasColumn('draft_analyses', 'headings_score')) {
                $table->unsignedTinyInteger('headings_score')->nullable()->after('cta_score');
            }

            if (! Schema::hasColumn('draft_analyses', 'signals_payload')) {
                $table->json('signals_payload')->nullable()->after('normalized_payload');
            }

            if (! Schema::hasColumn('draft_analyses', 'snapshot_signature')) {
                $table->string('snapshot_signature', 64)->nullable()->after('prompt_version');
                $table->index(['draft_id', 'snapshot_signature'], 'draft_analyses_draft_snapshot_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('draft_analyses', function (Blueprint $table): void {
            if (Schema::hasColumn('draft_analyses', 'snapshot_signature')) {
                $table->dropIndex('draft_analyses_draft_snapshot_idx');
            }

            $columns = [];

            foreach (['headings_score', 'signals_payload', 'snapshot_signature'] as $column) {
                if (Schema::hasColumn('draft_analyses', $column)) {
                    $columns[] = $column;
                }
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
