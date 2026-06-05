<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('async_operation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('content_destination_id')->nullable();
            $table->uuid('api_key_id')->nullable();
            $table->string('operation_type', 64);
            $table->string('status', 32)->default('queued')->index();
            $table->string('resource_type', 64)->nullable();
            $table->uuid('resource_id')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('result_payload')->nullable();
            $table->string('error_code', 120)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'operation_type', 'created_at'], 'async_op_workspace_type_created_idx');
            $table->index(['workspace_id', 'status', 'created_at'], 'async_op_workspace_status_created_idx');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('content_destination_id')->references('id')->on('content_destinations')->nullOnDelete();
            $table->foreign('api_key_id')->references('id')->on('api_keys')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('async_operation_runs');
    }
};
