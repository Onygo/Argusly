<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('llm_tracking_query_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('llm_tracking_query_runs', 'provider')) {
                $table->string('provider', 32)->nullable()->after('run_at');
                $table->index(['provider', 'run_at'], 'llm_track_runs_provider_runat_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('llm_tracking_query_runs', function (Blueprint $table): void {
            if (Schema::hasColumn('llm_tracking_query_runs', 'provider')) {
                $table->dropIndex('llm_track_runs_provider_runat_idx');
                $table->dropColumn('provider');
            }
        });
    }
};
