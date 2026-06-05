<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('content_ai_seo_scores')) {
            Schema::create('content_ai_seo_scores', function (Blueprint $table): void {
                $table->id();
                $table->string('url', 2000);
                $table->char('url_hash', 64)->unique();
                $table->decimal('content_roi_score', 8, 2)->default(0);
                $table->decimal('ai_visibility_score', 12, 2)->default(0);
                $table->decimal('ai_visibility_score_normalized', 8, 2)->default(0);
                $table->decimal('ai_seo_score', 8, 2)->default(0);
                $table->json('weights_json')->nullable();
                $table->timestamp('calculated_at')->nullable();
                $table->timestamps();

                $table->index('ai_seo_score', 'content_ai_seo_scores_score_idx');
            });
        }

        if (Schema::hasTable('content_ai_seo_scores')) {
            if (! Schema::hasColumn('content_ai_seo_scores', 'url_hash')) {
                Schema::table('content_ai_seo_scores', function (Blueprint $table): void {
                    $table->char('url_hash', 64)->nullable()->after('url');
                });
            }

            $driver = DB::connection()->getDriverName();

            if ($driver === 'mysql') {
                DB::statement(
                    "UPDATE `content_ai_seo_scores` SET `url_hash` = SHA2(`url`, 256) WHERE (`url_hash` IS NULL OR `url_hash` = '') AND `url` IS NOT NULL AND `url` <> ''"
                );

                // Keep the newest row per URL hash so a unique index can be created safely.
                DB::statement(
                    "DELETE old_rows FROM `content_ai_seo_scores` old_rows
                    INNER JOIN `content_ai_seo_scores` new_rows
                        ON old_rows.`url_hash` = new_rows.`url_hash`
                        AND old_rows.`id` < new_rows.`id`
                    WHERE old_rows.`url_hash` IS NOT NULL
                        AND old_rows.`url_hash` <> ''"
                );
            } else {
                DB::table('content_ai_seo_scores')
                    ->select(['id', 'url'])
                    ->where(function ($query): void {
                        $query->whereNull('url_hash')->orWhere('url_hash', '');
                    })
                    ->orderBy('id')
                    ->chunkById(500, function ($rows): void {
                        foreach ($rows as $row) {
                            $url = trim((string) ($row->url ?? ''));
                            if ($url === '') {
                                continue;
                            }

                            DB::table('content_ai_seo_scores')
                                ->where('id', $row->id)
                                ->update(['url_hash' => hash('sha256', $url)]);
                        }
                    });
            }

            // Drop legacy oversized unique index if present.
            try {
                DB::statement('ALTER TABLE `content_ai_seo_scores` DROP INDEX `content_ai_seo_scores_url_unique`');
            } catch (\Throwable) {
                // Ignore when index does not exist.
            }

            try {
                Schema::table('content_ai_seo_scores', function (Blueprint $table): void {
                    $table->unique('url_hash', 'content_ai_seo_scores_url_hash_unique');
                });
            } catch (\Throwable) {
                // Ignore duplicate-index errors.
            }

            try {
                Schema::table('content_ai_seo_scores', function (Blueprint $table): void {
                    $table->index('ai_seo_score', 'content_ai_seo_scores_score_idx');
                });
            } catch (\Throwable) {
                // Ignore duplicate-index errors.
            }
        }

        if (! Schema::hasTable('stats_metric_settings')) {
            Schema::create('stats_metric_settings', function (Blueprint $table): void {
                $table->id();
                $table->string('metric_key', 100)->unique();
                $table->json('settings_json')->nullable();
                $table->timestamp('calculated_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stats_metric_settings');
        Schema::dropIfExists('content_ai_seo_scores');
    }
};
