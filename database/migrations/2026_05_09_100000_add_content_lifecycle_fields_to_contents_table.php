<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            // Lifecycle stage - internal Argusly workflow state
            $table->string('lifecycle_stage', 32)->default('idea')->after('status');

            // Assignment tracking
            $table->foreignId('assigned_user_id')->nullable()->after('lifecycle_stage')->constrained('users')->nullOnDelete();
            $table->foreignId('reviewer_user_id')->nullable()->after('assigned_user_id')->constrained('users')->nullOnDelete();

            // Due date tracking
            $table->timestamp('due_at')->nullable()->after('reviewer_user_id');

            // Approval tracking
            $table->timestamp('approved_at')->nullable()->after('due_at');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();

            // Rejection tracking
            $table->timestamp('rejected_at')->nullable()->after('approved_by');
            $table->foreignId('rejected_by')->nullable()->after('rejected_at')->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable()->after('rejected_by');

            // Indexes for common queries
            $table->index(['lifecycle_stage', 'workspace_id'], 'contents_lifecycle_workspace_idx');
            $table->index(['assigned_user_id', 'lifecycle_stage'], 'contents_assigned_lifecycle_idx');
            $table->index(['reviewer_user_id', 'lifecycle_stage'], 'contents_reviewer_lifecycle_idx');
            $table->index(['due_at', 'lifecycle_stage'], 'contents_due_lifecycle_idx');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            // Drop indexes first
            $table->dropIndex('contents_lifecycle_workspace_idx');
            $table->dropIndex('contents_assigned_lifecycle_idx');
            $table->dropIndex('contents_reviewer_lifecycle_idx');
            $table->dropIndex('contents_due_lifecycle_idx');

            // Drop foreign key constraints
            $table->dropForeign(['assigned_user_id']);
            $table->dropForeign(['reviewer_user_id']);
            $table->dropForeign(['approved_by']);
            $table->dropForeign(['rejected_by']);

            // Drop columns
            $table->dropColumn([
                'lifecycle_stage',
                'assigned_user_id',
                'reviewer_user_id',
                'due_at',
                'approved_at',
                'approved_by',
                'rejected_at',
                'rejected_by',
                'rejection_reason',
            ]);
        });
    }
};
