<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_sources', function (Blueprint $table): void {
            $table->string('generation_status', 40)->default('pending')->after('extraction_status');
            $table->string('generation_failure_code', 80)->nullable()->after('generation_status');
            $table->text('generation_failure_message')->nullable()->after('generation_failure_code');
            $table->json('generation_diagnostics_json')->nullable()->after('generation_failure_message');
            $table->timestamp('generation_started_at')->nullable()->after('generation_diagnostics_json');
            $table->timestamp('generation_completed_at')->nullable()->after('generation_started_at');
            $table->string('generation_output_mode', 40)->nullable()->after('generation_completed_at');

            $table->index(['workspace_id', 'generation_status']);
        });
    }

    public function down(): void
    {
        Schema::table('content_sources', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'generation_status']);
            $table->dropColumn([
                'generation_status',
                'generation_failure_code',
                'generation_failure_message',
                'generation_diagnostics_json',
                'generation_started_at',
                'generation_completed_at',
                'generation_output_mode',
            ]);
        });
    }
};
