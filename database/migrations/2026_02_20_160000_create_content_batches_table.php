<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->unsignedBigInteger('user_id');
            $table->string('main_keyword', 255);
            $table->json('settings_json')->nullable();
            $table->string('status', 32)->default('draft');
            $table->unsignedSmallInteger('items_total')->default(0);
            $table->unsignedSmallInteger('items_done')->default(0);
            $table->unsignedInteger('credits_estimated')->default(0);
            $table->unsignedInteger('credits_used')->default(0);
            $table->timestamps();

            $table->index(['workspace_id', 'status'], 'content_batches_workspace_status_idx');
            $table->index(['workspace_id', 'created_at'], 'content_batches_workspace_created_idx');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_batches');
    }
};

