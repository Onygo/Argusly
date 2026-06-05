<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('briefs', function (Blueprint $table) {
            if (! Schema::hasColumn('briefs', 'wp_brief_id')) {
                $table->string('wp_brief_id')->nullable()->after('client_site_id');
            }
            if (! Schema::hasColumn('briefs', 'wp_post_id')) {
                $table->string('wp_post_id')->nullable()->after('wp_brief_id');
            }
        });

        DB::table('briefs')
            ->select(['id', 'client_refs'])
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $refs = json_decode((string) ($row->client_refs ?? ''), true);
                    if (! is_array($refs)) {
                        continue;
                    }

                    $wpBriefId = trim((string) ($refs['wp_brief_id'] ?? ''));
                    $wpPostId = trim((string) ($refs['wp_post_id'] ?? ''));

                    if ($wpBriefId === '' && $wpPostId === '') {
                        continue;
                    }

                    DB::table('briefs')
                        ->where('id', $row->id)
                        ->update([
                            'wp_brief_id' => $wpBriefId !== '' ? $wpBriefId : null,
                            'wp_post_id' => $wpPostId !== '' ? $wpPostId : null,
                        ]);
                }
            });

        Schema::table('briefs', function (Blueprint $table) {
            $table->index(['client_site_id', 'wp_brief_id'], 'briefs_client_wp_brief_idx');
            $table->index(['client_site_id', 'wp_post_id'], 'briefs_client_wp_post_idx');
        });
    }

    public function down(): void
    {
        Schema::table('briefs', function (Blueprint $table) {
            $table->dropIndex('briefs_client_wp_brief_idx');
            $table->dropIndex('briefs_client_wp_post_idx');
            $table->dropColumn(['wp_brief_id', 'wp_post_id']);
        });
    }
};

