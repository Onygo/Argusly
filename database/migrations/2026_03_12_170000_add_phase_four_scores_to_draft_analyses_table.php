<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('draft_analyses', function (Blueprint $table): void {
            if (! Schema::hasColumn('draft_analyses', 'brand_voice_fit_score')) {
                $table->unsignedTinyInteger('brand_voice_fit_score')->nullable()->after('llm_visibility_score');
            }

            if (! Schema::hasColumn('draft_analyses', 'conversion_fit_score')) {
                $table->unsignedTinyInteger('conversion_fit_score')->nullable()->after('brand_voice_fit_score');
            }

            if (! Schema::hasColumn('draft_analyses', 'trust_evidence_score')) {
                $table->unsignedTinyInteger('trust_evidence_score')->nullable()->after('conversion_fit_score');
            }

            if (! Schema::hasColumn('draft_analyses', 'publish_readiness_score')) {
                $table->unsignedTinyInteger('publish_readiness_score')->nullable()->after('trust_evidence_score');
            }

            if (! Schema::hasColumn('draft_analyses', 'publish_readiness_status')) {
                $table->string('publish_readiness_status', 40)->nullable()->after('publish_readiness_score');
            }

            if (! Schema::hasColumn('draft_analyses', 'publish_readiness_blocking_issues')) {
                $table->json('publish_readiness_blocking_issues')->nullable()->after('publish_readiness_status');
            }

            if (! Schema::hasColumn('draft_analyses', 'publish_readiness_next_actions')) {
                $table->json('publish_readiness_next_actions')->nullable()->after('publish_readiness_blocking_issues');
            }
        });
    }

    public function down(): void
    {
        Schema::table('draft_analyses', function (Blueprint $table): void {
            $columns = [];

            foreach ([
                'brand_voice_fit_score',
                'conversion_fit_score',
                'trust_evidence_score',
                'publish_readiness_score',
                'publish_readiness_status',
                'publish_readiness_blocking_issues',
                'publish_readiness_next_actions',
            ] as $column) {
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
