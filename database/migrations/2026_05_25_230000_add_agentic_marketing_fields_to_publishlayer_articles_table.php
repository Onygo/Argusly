<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('publishlayer_articles')) {
            return;
        }

        Schema::table('publishlayer_articles', function (Blueprint $table): void {
            if (! Schema::hasColumn('publishlayer_articles', 'locale')) {
                $table->string('locale', 32)->nullable()->after('featured_image_url')->index();
            }
            if (! Schema::hasColumn('publishlayer_articles', 'source_locale')) {
                $table->string('source_locale', 32)->nullable()->after('locale');
            }
            if (! Schema::hasColumn('publishlayer_articles', 'canonical_url')) {
                $table->text('canonical_url')->nullable()->after('source_locale');
            }
            if (! Schema::hasColumn('publishlayer_articles', 'canonical_content_id')) {
                $table->string('canonical_content_id')->nullable()->after('canonical_url')->index();
            }
            if (! Schema::hasColumn('publishlayer_articles', 'hreflang_alternates')) {
                $table->json('hreflang_alternates')->nullable()->after('canonical_content_id');
            }
            if (! Schema::hasColumn('publishlayer_articles', 'x_default_url')) {
                $table->text('x_default_url')->nullable()->after('hreflang_alternates');
            }
            if (! Schema::hasColumn('publishlayer_articles', 'translation_group_id')) {
                $table->string('translation_group_id')->nullable()->after('x_default_url')->index();
            }
            if (! Schema::hasColumn('publishlayer_articles', 'family_id')) {
                $table->string('family_id')->nullable()->after('translation_group_id')->index();
            }
            if (! Schema::hasColumn('publishlayer_articles', 'answer_blocks')) {
                $table->json('answer_blocks')->nullable()->after('family_id');
            }
            if (! Schema::hasColumn('publishlayer_articles', 'structured_output')) {
                $table->json('structured_output')->nullable()->after('answer_blocks');
            }
            if (! Schema::hasColumn('publishlayer_articles', 'schema_data')) {
                $table->json('schema_data')->nullable()->after('structured_output');
            }
            if (! Schema::hasColumn('publishlayer_articles', 'ai_visibility')) {
                $table->json('ai_visibility')->nullable()->after('schema_data');
            }
            if (! Schema::hasColumn('publishlayer_articles', 'metadata')) {
                $table->json('metadata')->nullable()->after('ai_visibility');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('publishlayer_articles')) {
            return;
        }

        Schema::table('publishlayer_articles', function (Blueprint $table): void {
            foreach ([
                'metadata',
                'ai_visibility',
                'schema_data',
                'structured_output',
                'answer_blocks',
                'family_id',
                'translation_group_id',
                'x_default_url',
                'hreflang_alternates',
                'canonical_content_id',
                'canonical_url',
                'source_locale',
                'locale',
            ] as $column) {
                if (Schema::hasColumn('publishlayer_articles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
