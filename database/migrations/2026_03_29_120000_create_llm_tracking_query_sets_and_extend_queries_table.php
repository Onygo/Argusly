<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('llm_tracking_query_sets')) {
            Schema::create('llm_tracking_query_sets', function (Blueprint $table): void {
                $table->id();
                $table->uuid('workspace_id');
                $table->uuid('client_site_id')->nullable();
                $table->string('name', 120);
                $table->text('description')->nullable();
                $table->string('locale', 16)->default('en');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['workspace_id', 'client_site_id', 'is_active'], 'llm_track_query_sets_scope_idx');
                $table->unique(['workspace_id', 'client_site_id', 'name'], 'llm_track_query_sets_name_unique');
                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign('client_site_id')->references('id')->on('client_sites')->cascadeOnDelete();
            });
        }

        Schema::table('llm_tracking_queries', function (Blueprint $table): void {
            if (! Schema::hasColumn('llm_tracking_queries', 'llm_tracking_query_set_id')) {
                $table->foreignId('llm_tracking_query_set_id')
                    ->nullable()
                    ->after('client_site_id')
                    ->constrained('llm_tracking_query_sets')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('llm_tracking_queries', 'target_brand')) {
                $table->string('target_brand', 160)->nullable()->after('query_text');
            }

            if (! Schema::hasColumn('llm_tracking_queries', 'target_domain')) {
                $table->string('target_domain', 255)->nullable()->after('target_brand');
            }

            if (! Schema::hasColumn('llm_tracking_queries', 'tags')) {
                $table->json('tags')->nullable()->after('target_urls');
            }

            if (! Schema::hasColumn('llm_tracking_queries', 'priority')) {
                $table->unsignedSmallInteger('priority')->default(50)->after('frequency');
                $table->index(['client_site_id', 'llm_tracking_query_set_id', 'priority'], 'llm_track_queries_set_priority_idx');
            }
        });

        DB::table('llm_tracking_queries')
            ->select(['id', 'brand_terms', 'target_urls'])
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $brandTerms = json_decode((string) ($row->brand_terms ?? '[]'), true);
                    $targetUrls = json_decode((string) ($row->target_urls ?? '[]'), true);

                    $targetBrand = collect(is_array($brandTerms) ? $brandTerms : [])
                        ->map(fn ($value): string => trim((string) $value))
                        ->filter()
                        ->first();

                    $targetDomain = collect(is_array($targetUrls) ? $targetUrls : [])
                        ->map(function ($value): string {
                            $domain = parse_url(trim((string) $value), PHP_URL_HOST);

                            return is_string($domain) ? strtolower(trim($domain)) : '';
                        })
                        ->filter()
                        ->first();

                    DB::table('llm_tracking_queries')
                        ->where('id', $row->id)
                        ->update([
                            'target_brand' => $targetBrand !== '' ? $targetBrand : null,
                            'target_domain' => $targetDomain !== '' ? $targetDomain : null,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('llm_tracking_queries', function (Blueprint $table): void {
            if (Schema::hasColumn('llm_tracking_queries', 'priority')) {
                $table->dropIndex('llm_track_queries_set_priority_idx');
            }

            if (Schema::hasColumn('llm_tracking_queries', 'llm_tracking_query_set_id')) {
                $table->dropConstrainedForeignId('llm_tracking_query_set_id');
            }

            foreach (['target_brand', 'target_domain', 'tags', 'priority'] as $column) {
                if (Schema::hasColumn('llm_tracking_queries', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('llm_tracking_query_sets');
    }
};
