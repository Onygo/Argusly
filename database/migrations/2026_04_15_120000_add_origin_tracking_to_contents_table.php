<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            // Origin tracking - placed after 'source' field
            $table->string('origin_type', 30)->default('unknown')->after('source');

            // Foreign keys for automation tracking
            $table->uuid('automation_id')->nullable()->after('origin_type');
            $table->uuid('automation_run_id')->nullable()->after('automation_id');

            // Foreign key for chain suggestion tracking
            $table->uuid('source_chain_suggestion_id')->nullable()->after('automation_run_id');

            // Publication timestamp (denormalized for efficient sorting)
            $table->timestamp('first_published_at')->nullable()->after('scheduled_publish_at');

            // Foreign key constraints
            $table->foreign('automation_id')
                ->references('id')
                ->on('content_automations')
                ->nullOnDelete();

            $table->foreign('automation_run_id')
                ->references('id')
                ->on('content_automation_runs')
                ->nullOnDelete();

            $table->foreign('source_chain_suggestion_id')
                ->references('id')
                ->on('content_chain_suggestions')
                ->nullOnDelete();

            // Indexes for filtering and sorting
            $table->index(['workspace_id', 'origin_type']);
            $table->index(['workspace_id', 'created_at']);
            $table->index(['workspace_id', 'first_published_at']);
            $table->index('automation_id');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            // Drop foreign keys
            $table->dropForeign(['automation_id']);
            $table->dropForeign(['automation_run_id']);
            $table->dropForeign(['source_chain_suggestion_id']);

            // Drop indexes
            $table->dropIndex(['workspace_id', 'origin_type']);
            $table->dropIndex(['workspace_id', 'created_at']);
            $table->dropIndex(['workspace_id', 'first_published_at']);
            $table->dropIndex(['automation_id']);

            // Drop columns
            $table->dropColumn([
                'origin_type',
                'automation_id',
                'automation_run_id',
                'source_chain_suggestion_id',
                'first_published_at',
            ]);
        });
    }
};
