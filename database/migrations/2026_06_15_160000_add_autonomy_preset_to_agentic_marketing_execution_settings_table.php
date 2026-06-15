<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agentic_marketing_execution_settings', function (Blueprint $table): void {
            $table->string('autonomy_preset', 64)
                ->default('guided_mode')
                ->after('brand_voice_id');
        });
    }

    public function down(): void
    {
        Schema::table('agentic_marketing_execution_settings', function (Blueprint $table): void {
            $table->dropColumn('autonomy_preset');
        });
    }
};
