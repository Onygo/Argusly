<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_settings_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('scope_type', 16); // global|workspace|site
            $table->string('scope_id', 64)->nullable();
            $table->string('action', 16); // created|updated|deleted
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamps();

            $table->index(['created_at'], 'llm_settings_audit_created_idx');
            $table->index(['actor_user_id', 'created_at'], 'llm_settings_audit_actor_created_idx');
            $table->index(['scope_type', 'scope_id'], 'llm_settings_audit_scope_idx');

            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_settings_audit_logs');
    }
};
