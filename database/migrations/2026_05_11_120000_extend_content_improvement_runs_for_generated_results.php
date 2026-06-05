<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_improvement_runs', function (Blueprint $table): void {
            $table->uuid('recommendation_run_id')->nullable()->after('draft_id')->index();
            $table->string('recommendation_key', 191)->nullable()->after('recommendation_run_id')->index();
            $table->uuid('source_content_id')->nullable()->after('recommendation_key')->index();
            $table->uuid('source_draft_id')->nullable()->after('source_content_id')->index();
            $table->uuid('source_content_version_id')->nullable()->after('source_draft_id')->index();
            $table->uuid('source_content_revision_id')->nullable()->after('source_content_version_id')->index();
            $table->string('source_revision_hash', 64)->nullable()->after('source_content_revision_id')->index();
            $table->uuid('target_draft_id')->nullable()->after('source_revision_hash')->index();
            $table->uuid('target_content_version_id')->nullable()->after('target_draft_id')->index();
            $table->string('output_revision_hash', 64)->nullable()->after('target_content_version_id')->index();
            $table->unsignedTinyInteger('before_score')->nullable()->after('output_revision_hash');
            $table->unsignedTinyInteger('after_score')->nullable()->after('before_score');
            $table->text('generated_summary')->nullable()->after('after_score');
            $table->text('diff_summary')->nullable()->after('generated_summary');
        });
    }

    public function down(): void
    {
        Schema::table('content_improvement_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'recommendation_run_id',
                'recommendation_key',
                'source_content_id',
                'source_draft_id',
                'source_content_version_id',
                'source_content_revision_id',
                'source_revision_hash',
                'target_draft_id',
                'target_content_version_id',
                'output_revision_hash',
                'before_score',
                'after_score',
                'generated_summary',
                'diff_summary',
            ]);
        });
    }
};
