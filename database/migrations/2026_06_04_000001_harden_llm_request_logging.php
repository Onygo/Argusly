<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('llm_requests', function (Blueprint $table): void {
            $table->decimal('actual_cost', 12, 6)->nullable()->after('estimated_cost');
            $table->string('prompt_version')->nullable()->after('error_message');
            $table->string('prompt_hash', 64)->nullable()->after('prompt_version');
            $table->foreignId('fallback_of_llm_request_id')->nullable()->after('prompt_hash')->constrained('llm_requests')->nullOnDelete();

            $table->index(['fallback_of_llm_request_id']);
            $table->index(['prompt_version', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('llm_requests', function (Blueprint $table): void {
            $table->dropIndex(['fallback_of_llm_request_id']);
            $table->dropIndex(['prompt_version', 'created_at']);
            $table->dropConstrainedForeignId('fallback_of_llm_request_id');
            $table->dropColumn(['actual_cost', 'prompt_version', 'prompt_hash']);
        });
    }
};
