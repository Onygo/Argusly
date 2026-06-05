<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('draft_analyses', function (Blueprint $table): void {
            $table->unsignedTinyInteger('llm_visibility_score')->nullable()->after('headings_score');
        });
    }

    public function down(): void
    {
        Schema::table('draft_analyses', function (Blueprint $table): void {
            $table->dropColumn('llm_visibility_score');
        });
    }
};
