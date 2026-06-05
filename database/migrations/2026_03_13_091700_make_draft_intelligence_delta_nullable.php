<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('draft_intelligence_deltas', function (Blueprint $table): void {
            $table->smallInteger('delta')->nullable()->default(null)->change();
        });

        DB::table('draft_intelligence_deltas')
            ->whereNull('score_before')
            ->orWhereNull('score_after')
            ->update(['delta' => null]);
    }

    public function down(): void
    {
        DB::table('draft_intelligence_deltas')
            ->whereNull('delta')
            ->update(['delta' => 0]);

        Schema::table('draft_intelligence_deltas', function (Blueprint $table): void {
            $table->smallInteger('delta')->default(0)->nullable(false)->change();
        });
    }
};
