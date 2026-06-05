<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_publish_targets', function (Blueprint $table) {
            $table->string('language', 5)->default('en')->after('target_identifier');
            $table->string('wp_language_plugin')->nullable()->after('language');
            $table->string('wp_language_term_id')->nullable()->after('wp_language_plugin');
            $table->string('remote_permalink')->nullable()->after('wp_featured_media_id');
            $table->string('remote_edit_link')->nullable()->after('remote_permalink');
            $table->string('external_key')->nullable()->after('remote_edit_link');

            $table->index(['content_id', 'language']);
            $table->index(['client_site_id', 'language']);
        });
    }

    public function down(): void
    {
        Schema::table('content_publish_targets', function (Blueprint $table) {
            $table->dropIndex(['client_site_id', 'language']);
            $table->dropIndex(['content_id', 'language']);
            $table->dropColumn([
                'language',
                'wp_language_plugin',
                'wp_language_term_id',
                'remote_permalink',
                'remote_edit_link',
                'external_key',
            ]);
        });
    }
};
