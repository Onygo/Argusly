<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('draft_comparison_scores')) {
            return;
        }

        if (Schema::hasColumn('draft_comparison_scores', 'source_type')) {
            return;
        }

        Schema::table('draft_comparison_scores', function (Blueprint $table): void {
            $table->string('source_type', 64)->nullable()->after('metric_group');
            $table->index('source_type', 'draft_compare_scores_source_type_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('draft_comparison_scores')) {
            return;
        }

        if (! Schema::hasColumn('draft_comparison_scores', 'source_type')) {
            return;
        }

        Schema::table('draft_comparison_scores', function (Blueprint $table): void {
            $table->dropIndex('draft_compare_scores_source_type_idx');
            $table->dropColumn('source_type');
        });
    }
};
