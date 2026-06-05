<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('draft_analyses', function (Blueprint $table): void {
            if (! Schema::hasColumn('draft_analyses', 'normalized_payload')) {
                $table->json('normalized_payload')->nullable()->after('suggestions');
            }

            if (! Schema::hasColumn('draft_analyses', 'analysis_provider')) {
                $table->string('analysis_provider', 50)->nullable()->after('analysis_model');
            }

            if (! Schema::hasColumn('draft_analyses', 'prompt_version')) {
                $table->string('prompt_version', 50)->nullable()->after('analysis_provider');
            }
        });
    }

    public function down(): void
    {
        Schema::table('draft_analyses', function (Blueprint $table): void {
            $columns = [];

            foreach (['normalized_payload', 'analysis_provider', 'prompt_version'] as $column) {
                if (Schema::hasColumn('draft_analyses', $column)) {
                    $columns[] = $column;
                }
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
