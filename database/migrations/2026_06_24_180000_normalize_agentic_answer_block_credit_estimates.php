<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agentic_marketing_actions')
            || ! Schema::hasColumn('agentic_marketing_actions', 'credits_reserved')
            || ! Schema::hasColumn('agentic_marketing_actions', 'credits_captured')) {
            return;
        }

        DB::table('agentic_marketing_actions')
            ->where('action_type', 'add_answer_block')
            ->where('estimated_credits', 6)
            ->where(function ($query): void {
                $query->whereNull('credits_reserved')
                    ->orWhere('credits_reserved', 0);
            })
            ->where(function ($query): void {
                $query->whereNull('credits_captured')
                    ->orWhere('credits_captured', 0);
            })
            ->orderBy('id')
            ->chunkById(100, function ($actions): void {
                foreach ($actions as $action) {
                    $payload = json_decode((string) ($action->payload ?? ''), true);

                    if (! is_array($payload)) {
                        $payload = [];
                    }

                    data_set($payload, 'planning.estimated_credits', 2);

                    $signals = data_get($payload, 'proposal_details.items.5.signals');
                    if (is_array($signals)) {
                        data_set($payload, 'proposal_details.items.5.signals', array_map(
                            fn (mixed $signal): mixed => $signal === 'estimated_credits: 6' ? 'estimated_credits: 2' : $signal,
                            $signals
                        ));
                    }

                    DB::table('agentic_marketing_actions')
                        ->where('id', $action->id)
                        ->update([
                            'estimated_credits' => 2,
                            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Data normalization only; do not inflate existing estimates on rollback.
    }
};
