<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->string('answer_block_generation_status', 32)->nullable()->after('internal_links_meta')->index();
            $table->unsignedSmallInteger('answer_block_generation_persisted_count')->default(0)->after('answer_block_generation_status');
            $table->uuid('answer_block_generation_draft_revision_id')->nullable()->after('answer_block_generation_persisted_count');
            $table->timestamp('answer_block_generation_started_at')->nullable()->after('answer_block_generation_draft_revision_id');
            $table->timestamp('answer_block_generation_completed_at')->nullable()->after('answer_block_generation_started_at');
            $table->timestamp('answer_block_generation_failed_at')->nullable()->after('answer_block_generation_completed_at');
            $table->text('answer_block_generation_last_error')->nullable()->after('answer_block_generation_failed_at');
            $table->text('answer_block_generation_last_warning')->nullable()->after('answer_block_generation_last_error');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->dropColumn([
                'answer_block_generation_status',
                'answer_block_generation_persisted_count',
                'answer_block_generation_draft_revision_id',
                'answer_block_generation_started_at',
                'answer_block_generation_completed_at',
                'answer_block_generation_failed_at',
                'answer_block_generation_last_error',
                'answer_block_generation_last_warning',
            ]);
        });
    }
};
