<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_blog_redirects')) {
            Schema::create('marketing_blog_redirects', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('source_path', 512);
                $table->string('source_locale', 10);
                $table->string('source_slug', 255);
                $table->string('target_path', 512);
                $table->string('target_locale', 10);
                $table->string('target_slug', 255);
                $table->uuid('target_content_id')->nullable();
                $table->string('redirect_kind', 64)->default('legacy_locale_mismatch');
                $table->boolean('is_active')->default(true);
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('marketing_blog_redirects', function (Blueprint $table): void {
            $table->string('source_path', 512)->change();
            $table->string('target_path', 512)->change();
        });

        if (! $this->hasIndex('marketing_blog_redirects', 'marketing_blog_redirects_source_path_unique')) {
            Schema::table('marketing_blog_redirects', function (Blueprint $table): void {
                $table->unique('source_path');
            });
        }

        if (! $this->hasIndex('marketing_blog_redirects', 'marketing_blog_redirects_source_lookup_idx')) {
            Schema::table('marketing_blog_redirects', function (Blueprint $table): void {
                $table->index(['source_locale', 'source_slug'], 'marketing_blog_redirects_source_lookup_idx');
            });
        }

        if (! $this->hasIndex('marketing_blog_redirects', 'marketing_blog_redirects_target_content_idx')) {
            Schema::table('marketing_blog_redirects', function (Blueprint $table): void {
                $table->index(['target_content_id', 'redirect_kind'], 'marketing_blog_redirects_target_content_idx');
            });
        }

        if (! $this->hasForeignKey('marketing_blog_redirects', 'marketing_blog_redirects_target_content_fk')) {
            Schema::table('marketing_blog_redirects', function (Blueprint $table): void {
                $table->foreign('target_content_id', 'marketing_blog_redirects_target_content_fk')
                    ->references('id')
                    ->on('contents')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('marketing_blog_redirects')) {
            Schema::table('marketing_blog_redirects', function (Blueprint $table): void {
                if ($this->hasForeignKey('marketing_blog_redirects', 'marketing_blog_redirects_target_content_fk')) {
                    $table->dropForeign('marketing_blog_redirects_target_content_fk');
                }

                if ($this->hasIndex('marketing_blog_redirects', 'marketing_blog_redirects_source_path_unique')) {
                    $table->dropUnique('marketing_blog_redirects_source_path_unique');
                }

                if ($this->hasIndex('marketing_blog_redirects', 'marketing_blog_redirects_source_lookup_idx')) {
                    $table->dropIndex('marketing_blog_redirects_source_lookup_idx');
                }

                if ($this->hasIndex('marketing_blog_redirects', 'marketing_blog_redirects_target_content_idx')) {
                    $table->dropIndex('marketing_blog_redirects_target_content_idx');
                }
            });
        }

        Schema::dropIfExists('marketing_blog_redirects');
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $indexes = DB::select(sprintf('PRAGMA index_list(%s)', DB::getPdo()->quote($table)));

            return collect($indexes)->contains(function ($index) use ($indexName): bool {
                return (string) ($index->name ?? $index->Name ?? '') === $indexName;
            });
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->exists();
    }

    private function hasForeignKey(string $table, string $constraintName): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return false;
        }

        return DB::table('information_schema.table_constraints')
            ->where('constraint_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('constraint_name', $constraintName)
            ->where('constraint_type', 'FOREIGN KEY')
            ->exists();
    }
};
