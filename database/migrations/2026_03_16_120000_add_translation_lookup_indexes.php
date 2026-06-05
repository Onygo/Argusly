<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->index(
                ['source_draft_id', 'language', 'draft_type'],
                'drafts_translation_lookup_idx'
            );
        });

        Schema::table('content_publish_targets', function (Blueprint $table) {
            $table->index(
                ['content_id', 'target_type', 'language'],
                'content_publish_targets_content_type_language_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('content_publish_targets', function (Blueprint $table) {
            $table->dropIndex('content_publish_targets_content_type_language_idx');
        });

        Schema::table('drafts', function (Blueprint $table) {
            $table->dropIndex('drafts_translation_lookup_idx');
        });
    }
};
