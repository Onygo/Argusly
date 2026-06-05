<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            if (! Schema::hasColumn('contents', 'public_blog_excerpt')) {
                $table->text('public_blog_excerpt')->nullable()->after('seo_meta_description');
            }

            if (! Schema::hasColumn('contents', 'public_blog_reading_time_minutes')) {
                $table->unsignedSmallInteger('public_blog_reading_time_minutes')->nullable()->after('public_blog_excerpt');
            }

            if (! Schema::hasColumn('contents', 'public_blog_author')) {
                $table->string('public_blog_author', 191)->nullable()->after('public_blog_reading_time_minutes');
            }

            if (! Schema::hasColumn('contents', 'public_blog_category')) {
                $table->string('public_blog_category', 191)->nullable()->after('public_blog_author');
            }

            if (! Schema::hasColumn('contents', 'public_blog_tags')) {
                $table->json('public_blog_tags')->nullable()->after('public_blog_category');
            }

            if (! Schema::hasColumn('contents', 'public_blog_featured_image_url')) {
                $table->string('public_blog_featured_image_url', 2048)->nullable()->after('public_blog_tags');
            }

            if (! Schema::hasColumn('contents', 'public_blog_featured_image_width')) {
                $table->unsignedInteger('public_blog_featured_image_width')->nullable()->after('public_blog_featured_image_url');
            }

            if (! Schema::hasColumn('contents', 'public_blog_featured_image_height')) {
                $table->unsignedInteger('public_blog_featured_image_height')->nullable()->after('public_blog_featured_image_width');
            }
        });

        $this->addIndexIfMissing('contents', 'contents_blog_scope_locale_pub_idx', function (Blueprint $table): void {
            $table->index(
                ['workspace_id', 'type', 'language', 'status', 'publish_status', 'first_published_at'],
                'contents_blog_scope_locale_pub_idx'
            );
        });

        $this->addIndexIfMissing('contents', 'contents_blog_site_locale_pub_idx', function (Blueprint $table): void {
            $table->index(
                ['client_site_id', 'type', 'language', 'status', 'publish_status', 'first_published_at'],
                'contents_blog_site_locale_pub_idx'
            );
        });

        $this->addIndexIfMissing('contents', 'contents_blog_scope_slug_locale_idx', function (Blueprint $table): void {
            $table->index(['workspace_id', 'language', 'publish_url_key'], 'contents_blog_scope_slug_locale_idx');
        });

        $this->addIndexIfMissing('contents', 'contents_blog_site_slug_locale_idx', function (Blueprint $table): void {
            $table->index(['client_site_id', 'language', 'publish_url_key'], 'contents_blog_site_slug_locale_idx');
        });
    }

    public function down(): void
    {
        $this->dropIndexIfExists('contents', 'contents_blog_site_slug_locale_idx');
        $this->dropIndexIfExists('contents', 'contents_blog_scope_slug_locale_idx');
        $this->dropIndexIfExists('contents', 'contents_blog_site_locale_pub_idx');
        $this->dropIndexIfExists('contents', 'contents_blog_scope_locale_pub_idx');

        Schema::table('contents', function (Blueprint $table): void {
            foreach ([
                'public_blog_excerpt',
                'public_blog_reading_time_minutes',
                'public_blog_author',
                'public_blog_category',
                'public_blog_tags',
                'public_blog_featured_image_url',
                'public_blog_featured_image_width',
                'public_blog_featured_image_height',
            ] as $column) {
                if (Schema::hasColumn('contents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /**
     * @param  callable(Blueprint):void  $callback
     */
    private function addIndexIfMissing(string $table, string $name, callable $callback): void
    {
        if ($this->hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($callback): void {
            $callback($blueprint);
        });
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        if (! $this->hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name): void {
            $blueprint->dropIndex($name);
        });
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => (string) ($index['name'] ?? '') === $name);
    }
};
