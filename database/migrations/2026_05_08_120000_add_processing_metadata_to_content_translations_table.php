<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_translations', function (Blueprint $table): void {
            $table->timestamp('processing_started_at')->nullable()->after('job_id');
            $table->string('processing_job_uuid', 128)->nullable()->after('processing_started_at');
            $table->timestamp('processing_locked_at')->nullable()->after('processing_job_uuid');
            $table->timestamp('processing_last_heartbeat_at')->nullable()->after('processing_locked_at');
            $table->timestamp('processing_failed_at')->nullable()->after('processing_last_heartbeat_at');
            $table->text('processing_error_message')->nullable()->after('processing_failed_at');

            $table->index(['processing_job_uuid'], 'content_translations_processing_job_uuid_idx');
            $table->index(['processing_locked_at'], 'content_translations_processing_locked_at_idx');
            $table->index(['processing_last_heartbeat_at'], 'content_translations_processing_heartbeat_idx');
        });
    }

    public function down(): void
    {
        Schema::table('content_translations', function (Blueprint $table): void {
            $table->dropIndex('content_translations_processing_job_uuid_idx');
            $table->dropIndex('content_translations_processing_locked_at_idx');
            $table->dropIndex('content_translations_processing_heartbeat_idx');

            $table->dropColumn([
                'processing_started_at',
                'processing_job_uuid',
                'processing_locked_at',
                'processing_last_heartbeat_at',
                'processing_failed_at',
                'processing_error_message',
            ]);
        });
    }
};
