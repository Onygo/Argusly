<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('drafts')) {
            return;
        }

        Schema::table('drafts', function (Blueprint $table): void {
            if (! Schema::hasColumn('drafts', 'draft_comparison_id')) {
                $table->uuid('draft_comparison_id')->nullable()->after('content_id');
                $table->index('draft_comparison_id', 'drafts_draft_comparison_id_idx');
            }

            if (! Schema::hasColumn('drafts', 'draft_comparison_variant_id')) {
                $table->uuid('draft_comparison_variant_id')->nullable()->after('draft_comparison_id');
                $table->index('draft_comparison_variant_id', 'drafts_draft_comparison_variant_id_idx');
            }
        });

        Schema::table('drafts', function (Blueprint $table): void {
            if (Schema::hasColumn('drafts', 'draft_comparison_id')) {
                $table->foreign('draft_comparison_id', 'drafts_draft_comparison_id_foreign')
                    ->references('id')
                    ->on('draft_comparisons')
                    ->nullOnDelete();
            }

            if (Schema::hasColumn('drafts', 'draft_comparison_variant_id')) {
                $table->foreign('draft_comparison_variant_id', 'drafts_draft_comparison_variant_id_foreign')
                    ->references('id')
                    ->on('draft_comparison_variants')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('drafts')) {
            return;
        }

        Schema::table('drafts', function (Blueprint $table): void {
            if (Schema::hasColumn('drafts', 'draft_comparison_variant_id')) {
                $table->dropForeign('drafts_draft_comparison_variant_id_foreign');
                $table->dropIndex('drafts_draft_comparison_variant_id_idx');
                $table->dropColumn('draft_comparison_variant_id');
            }

            if (Schema::hasColumn('drafts', 'draft_comparison_id')) {
                $table->dropForeign('drafts_draft_comparison_id_foreign');
                $table->dropIndex('drafts_draft_comparison_id_idx');
                $table->dropColumn('draft_comparison_id');
            }
        });
    }
};
