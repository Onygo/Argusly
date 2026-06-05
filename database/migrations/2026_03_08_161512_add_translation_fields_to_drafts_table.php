<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->string('language', 5)->default('en')->after('output_type');
            $table->string('draft_type', 20)->default('original')->after('language');
            $table->uuid('source_draft_id')->nullable()->after('draft_type');
            $table->string('translation_source_language', 5)->nullable()->after('source_draft_id');
            $table->string('model_used')->nullable()->after('translation_source_language');

            $table->index('language');
            $table->index('draft_type');
            $table->index('source_draft_id');

            $table->foreign('source_draft_id')
                ->references('id')
                ->on('drafts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->dropForeign(['source_draft_id']);
            $table->dropIndex(['source_draft_id']);
            $table->dropIndex(['draft_type']);
            $table->dropIndex(['language']);
            $table->dropColumn([
                'language',
                'draft_type',
                'source_draft_id',
                'translation_source_language',
                'model_used',
            ]);
        });
    }
};
