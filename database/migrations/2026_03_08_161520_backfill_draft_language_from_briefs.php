<?php

use App\Models\Draft;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Skip if drafts table doesn't have language column yet (shouldn't happen but safety check)
        if (! Schema::hasColumn('drafts', 'language')) {
            return;
        }

        // Use Eloquent chunking for cross-database compatibility (MySQL + SQLite)
        $processed = 0;

        Draft::query()
            ->where(function ($query) {
                $query->whereNull('language')
                    ->orWhere('language', '');
            })
            ->whereNotNull('brief_id')
            ->with('brief:id,language')
            ->chunkById(500, function ($drafts) use (&$processed) {
                foreach ($drafts as $draft) {
                    $briefLanguage = $draft->brief?->language ?? 'en';
                    $draft->updateQuietly([
                        'language' => $briefLanguage,
                        'draft_type' => 'original',
                    ]);
                    $processed++;
                }
            });

        if ($processed > 0) {
            Log::info("Backfilled language for {$processed} drafts from briefs");
        }

        // Handle orphan drafts (no brief)
        $orphanUpdated = DB::table('drafts')
            ->where(function ($query) {
                $query->whereNull('language')
                    ->orWhere('language', '');
            })
            ->update([
                'language' => 'en',
                'draft_type' => 'original',
            ]);

        if ($orphanUpdated > 0) {
            Log::info("Set default language for {$orphanUpdated} orphan drafts");
        }

        Log::info("Draft language backfill completed. Total processed: " . ($processed + $orphanUpdated));
    }

    public function down(): void
    {
    }
};
