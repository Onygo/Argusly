<?php

use App\Models\SeoAuditFixSuggestion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('seo_audit_fix_suggestions')) {
            return;
        }

        Schema::table('seo_audit_fix_suggestions', function (Blueprint $table): void {
            if (! Schema::hasColumn('seo_audit_fix_suggestions', 'suggestion_state')) {
                $table->string('suggestion_state', 32)
                    ->default(SeoAuditFixSuggestion::STATE_SUGGESTED)
                    ->after('status');
            }
        });

        $indexName = 'seo_audit_fix_suggestions_state_idx';
        $hasIndex = collect(Schema::getIndexes('seo_audit_fix_suggestions'))
            ->contains(fn (array $index): bool => (string) ($index['name'] ?? '') === $indexName);

        if (! $hasIndex) {
            Schema::table('seo_audit_fix_suggestions', function (Blueprint $table) use ($indexName): void {
                $table->index('suggestion_state', $indexName);
            });
        }

        DB::table('seo_audit_fix_suggestions')
            ->whereNull('suggestion_state')
            ->orWhere('suggestion_state', '')
            ->update([
                'suggestion_state' => DB::raw(
                    "CASE WHEN status = '" . SeoAuditFixSuggestion::STATUS_APPLIED . "' THEN '" . SeoAuditFixSuggestion::STATE_APPLIED_LOCAL . "' ELSE '" . SeoAuditFixSuggestion::STATE_SUGGESTED . "' END"
                ),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('seo_audit_fix_suggestions')) {
            return;
        }

        $indexName = 'seo_audit_fix_suggestions_state_idx';
        $hasIndex = collect(Schema::getIndexes('seo_audit_fix_suggestions'))
            ->contains(fn (array $index): bool => (string) ($index['name'] ?? '') === $indexName);

        Schema::table('seo_audit_fix_suggestions', function (Blueprint $table) use ($hasIndex, $indexName): void {
            if ($hasIndex) {
                $table->dropIndex($indexName);
            }

            if (Schema::hasColumn('seo_audit_fix_suggestions', 'suggestion_state')) {
                $table->dropColumn('suggestion_state');
            }
        });
    }
};
