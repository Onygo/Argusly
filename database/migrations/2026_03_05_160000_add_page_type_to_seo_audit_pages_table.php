<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('seo_audit_pages')) {
            return;
        }

        Schema::table('seo_audit_pages', function (Blueprint $table): void {
            if (! Schema::hasColumn('seo_audit_pages', 'page_type')) {
                $table->string('page_type', 32)->nullable()->after('broken_links_count');
            }

            if (! Schema::hasColumn('seo_audit_pages', 'publishlayer_article_id')) {
                $table->uuid('publishlayer_article_id')->nullable()->after('page_type');
            }
        });

        DB::table('seo_audit_pages')
            ->whereNull('page_type')
            ->update(['page_type' => 'site_page']);

        Schema::table('seo_audit_pages', function (Blueprint $table): void {
            $table->index(['seo_audit_id', 'page_type'], 'seo_audit_pages_audit_type_idx');
            $table->index('publishlayer_article_id', 'seo_audit_pages_article_idx');
            $table->foreign('publishlayer_article_id', 'seo_audit_pages_article_fk')
                ->references('id')
                ->on('contents')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('seo_audit_pages')) {
            return;
        }

        Schema::table('seo_audit_pages', function (Blueprint $table): void {
            $table->dropForeign('seo_audit_pages_article_fk');
            $table->dropIndex('seo_audit_pages_audit_type_idx');
            $table->dropIndex('seo_audit_pages_article_idx');

            if (Schema::hasColumn('seo_audit_pages', 'publishlayer_article_id')) {
                $table->dropColumn('publishlayer_article_id');
            }

            if (Schema::hasColumn('seo_audit_pages', 'page_type')) {
                $table->dropColumn('page_type');
            }
        });
    }
};
