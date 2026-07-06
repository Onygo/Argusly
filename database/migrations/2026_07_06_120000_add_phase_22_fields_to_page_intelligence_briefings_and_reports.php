<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_page_intelligence_briefings', function (Blueprint $table): void {
            $table->timestamp('last_failed_at')->nullable()->after('last_generated_at');
            $table->text('last_error')->nullable()->after('last_failed_at');
            $table->unsignedInteger('failure_count')->default(0)->after('last_error');
            $table->timestamp('scheduler_claimed_at')->nullable()->after('next_run_at');
            $table->timestamp('scheduler_claim_expires_at')->nullable()->after('scheduler_claimed_at')->index('scheduled_pi_briefings_claim_expires_idx');
            $table->string('scheduler_claim_token', 80)->nullable()->after('scheduler_claim_expires_at')->index('scheduled_pi_briefings_claim_token_idx');
        });

        Schema::table('page_intelligence_reports', function (Blueprint $table): void {
            $table->char('artifact_source_checksum', 64)->nullable()->after('artifact_checksum');
            $table->timestamp('artifact_failed_at')->nullable()->after('artifact_generated_at');
            $table->text('artifact_error')->nullable()->after('artifact_failed_at');
            $table->unsignedInteger('artifact_attempt_count')->default(0)->after('artifact_error');
        });
    }

    public function down(): void
    {
        Schema::table('page_intelligence_reports', function (Blueprint $table): void {
            $table->dropColumn([
                'artifact_source_checksum',
                'artifact_failed_at',
                'artifact_error',
                'artifact_attempt_count',
            ]);
        });

        Schema::table('scheduled_page_intelligence_briefings', function (Blueprint $table): void {
            $table->dropIndex('scheduled_pi_briefings_claim_expires_idx');
            $table->dropIndex('scheduled_pi_briefings_claim_token_idx');
            $table->dropColumn([
                'last_failed_at',
                'last_error',
                'failure_count',
                'scheduler_claimed_at',
                'scheduler_claim_expires_at',
                'scheduler_claim_token',
            ]);
        });
    }
};
