<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $oldValues = [
            'gemini-2.5-flash-image-preview',
            'models/gemini-2.5-flash-image-preview',
        ];
        $newValue = 'gemini-2.5-flash-image';

        if (Schema::hasTable('llm_global_settings')) {
            $rows = DB::table('llm_global_settings')
                ->select(['id', 'default_image_model_map'])
                ->get();

            foreach ($rows as $row) {
                $map = $row->default_image_model_map;
                if (is_string($map)) {
                    $decoded = json_decode($map, true);
                    $map = is_array($decoded) ? $decoded : [];
                }

                if (! is_array($map)) {
                    $map = [];
                }

                $geminiModel = trim((string) ($map['gemini'] ?? ''));
                if (! in_array($geminiModel, $oldValues, true)) {
                    continue;
                }

                $map['gemini'] = $newValue;

                DB::table('llm_global_settings')
                    ->where('id', $row->id)
                    ->update([
                        'default_image_model_map' => json_encode($map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'updated_at' => now(),
                    ]);
            }
        }

        if (Schema::hasTable('llm_routing_rules')) {
            DB::table('llm_routing_rules')
                ->whereIn('model', $oldValues)
                ->update([
                    'model' => $newValue,
                    'updated_at' => now(),
                ]);

            DB::table('llm_routing_rules')
                ->whereIn('fallback_model', $oldValues)
                ->update([
                    'fallback_model' => $newValue,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Intentionally left blank: converting back may overwrite valid newer values.
    }
};

