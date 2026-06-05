<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->backfillLifecycleStages();
    }

    public function down(): void
    {
        // Reset all lifecycle_stage values to default
        DB::table('contents')->update(['lifecycle_stage' => 'idea']);
    }

    private function backfillLifecycleStages(): void
    {
        // Map legacy status values to lifecycle_stage
        $mappings = [
            // Brief stages
            'brief_received' => 'brief',
            'brief' => 'brief',

            // Draft stages
            'draft' => 'draft',
            'generating' => 'draft',
            'generated' => 'draft',

            // Review stage
            'review' => 'review',

            // Approved stage (previously ready_to_deliver or approved)
            'approved' => 'approved',
            'ready_to_deliver' => 'approved',

            // Scheduled stage
            'scheduled' => 'scheduled',

            // Published stage (previously published or delivered)
            'published' => 'published',
            'delivered' => 'published',

            // Archived stage
            'archived' => 'archived',
        ];

        foreach ($mappings as $legacyStatus => $lifecycleStage) {
            DB::table('contents')
                ->where('status', $legacyStatus)
                ->update(['lifecycle_stage' => $lifecycleStage]);
        }

        // Any remaining content without a valid mapping defaults to 'idea'
        DB::table('contents')
            ->whereNull('status')
            ->orWhere('status', '')
            ->update(['lifecycle_stage' => 'idea']);
    }
};
