<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recommendations', function (Blueprint $table): void {
            $table->string('action_type')->nullable()->after('recommended_action')->index();
            $table->json('action_payload')->nullable()->after('action_type');
            $table->foreignId('accepted_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable()->after('accepted_by');
            $table->timestamp('executed_at')->nullable()->after('accepted_at');
            $table->string('execution_status')->nullable()->after('executed_at')->index();

            $table->index(['account_id', 'brand_id', 'action_type', 'execution_status'], 'recommendations_action_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('recommendations', function (Blueprint $table): void {
            $table->dropIndex('recommendations_action_status_index');
            $table->dropConstrainedForeignId('accepted_by');
            $table->dropColumn(['action_type', 'action_payload', 'accepted_at', 'executed_at', 'execution_status']);
        });
    }
};
