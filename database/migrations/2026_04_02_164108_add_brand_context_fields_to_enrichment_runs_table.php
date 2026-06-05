<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('enrichment_runs', function (Blueprint $table) {
            $table->json('requested_sections')->nullable()->after('ai_payload');
            $table->string('generation_mode', 32)->nullable()->after('requested_sections');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('enrichment_runs', function (Blueprint $table) {
            $table->dropColumn(['requested_sections', 'generation_mode']);
        });
    }
};
