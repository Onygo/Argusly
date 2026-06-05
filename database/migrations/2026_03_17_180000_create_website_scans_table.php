<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_scans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('organization_id');
            $table->uuid('workspace_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->string('url', 2048);
            $table->string('status', 32)->default('queued')->index();
            $table->float('progress')->default(0);
            $table->json('crawled_pages')->nullable();
            $table->json('extracted_content')->nullable();
            $table->json('brand_profile')->nullable();
            $table->json('seo_profile')->nullable();
            $table->json('design_profile')->nullable();
            $table->json('technical_profile')->nullable();
            $table->json('suggested_briefs')->nullable();
            $table->boolean('user_confirmed')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error_code', 120)->nullable();
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['organization_id', 'status', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_scans');
    }
};
