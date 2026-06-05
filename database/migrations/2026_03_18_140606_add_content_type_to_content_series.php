<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds content_type field to content_series table.
 *
 * This allows series to specify the target WordPress post type (post, knowledge_base)
 * which determines both the URL pattern for internal links and the WP REST endpoint used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_series', function (Blueprint $table) {
            $table->string('content_type', 32)
                ->default('post')
                ->after('articles_count')
                ->comment('WordPress post type: post, knowledge_base, page');
        });
    }

    public function down(): void
    {
        Schema::table('content_series', function (Blueprint $table) {
            $table->dropColumn('content_type');
        });
    }
};
