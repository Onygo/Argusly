<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $duplicates = DB::table('content_publish_targets')
                ->select('client_site_id', 'content_id', 'target_type', DB::raw('COUNT(*) as total'))
                ->groupBy('client_site_id', 'content_id', 'target_type')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            foreach ($duplicates as $duplicate) {
                $idsToKeep = DB::table('content_publish_targets')
                    ->where('client_site_id', $duplicate->client_site_id)
                    ->where('content_id', $duplicate->content_id)
                    ->where('target_type', $duplicate->target_type)
                    ->orderByDesc('updated_at')
                    ->orderByDesc('created_at')
                    ->limit(1)
                    ->pluck('id');

                DB::table('content_publish_targets')
                    ->where('client_site_id', $duplicate->client_site_id)
                    ->where('content_id', $duplicate->content_id)
                    ->where('target_type', $duplicate->target_type)
                    ->whereNotIn('id', $idsToKeep)
                    ->delete();
            }
        });

        Schema::table('content_publish_targets', function (Blueprint $table) {
            $table->unique(
                ['client_site_id', 'content_id', 'target_type'],
                'content_publish_targets_site_content_target_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('content_publish_targets', function (Blueprint $table) {
            $table->dropUnique('content_publish_targets_site_content_target_unique');
        });
    }
};
