<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('workspace_id');
            $table->uuid('api_key_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('method', 10);
            $table->string('path', 700);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->unsignedSmallInteger('response_status');
            $table->integer('credits_reserved')->nullable();
            $table->integer('credits_used')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('requested_at');
            $table->timestamps();

            $table->index(['workspace_id', 'requested_at'], 'api_request_logs_workspace_requested_idx');
            $table->index(['api_key_id', 'requested_at'], 'api_request_logs_key_requested_idx');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('api_key_id')->references('id')->on('api_keys')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
    }
};
