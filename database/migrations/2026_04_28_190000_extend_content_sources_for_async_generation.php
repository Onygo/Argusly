<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_sources', function (Blueprint $table): void {
            $table->string('generation_progress_step', 80)->nullable()->after('generation_status');
            $table->string('generation_locale', 8)->nullable()->after('generation_output_mode');
            $table->string('generation_intent', 40)->nullable()->after('generation_locale');
            $table->string('generation_idempotency_key', 191)->nullable()->after('generation_intent');
            $table->uuid('result_content_id')->nullable()->after('generation_idempotency_key');
            $table->uuid('result_brief_id')->nullable()->after('result_content_id');

            $table->index(['workspace_id', 'created_by_user_id', 'generation_idempotency_key'], 'content_sources_generation_idempotency_idx');
        });
    }

    public function down(): void
    {
        Schema::table('content_sources', function (Blueprint $table): void {
            $table->dropIndex('content_sources_generation_idempotency_idx');
            $table->dropColumn([
                'generation_progress_step',
                'generation_locale',
                'generation_intent',
                'generation_idempotency_key',
                'result_content_id',
                'result_brief_id',
            ]);
        });
    }
};
