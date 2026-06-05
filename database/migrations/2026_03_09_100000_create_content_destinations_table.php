<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_destinations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->string('name', 120);
            $table->string('type', 40)->index();
            $table->string('status', 32)->default('active')->index();
            $table->string('environment', 32)->default('production');
            $table->json('config')->nullable();
            $table->string('default_language', 10)->default('en');
            $table->string('default_content_type', 64)->nullable();
            $table->string('export_format', 32)->nullable();
            $table->boolean('tracking_enabled')->default(true);
            $table->boolean('seo_audit_enabled')->default(true);
            $table->string('webhook_url', 2048)->nullable();
            $table->text('webhook_secret')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'name'], 'content_destinations_workspace_name_unique');
            $table->index(['workspace_id', 'type'], 'content_destinations_workspace_type_idx');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_destinations');
    }
};
