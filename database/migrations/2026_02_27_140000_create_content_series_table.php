<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_series', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('organization_id')->index();
            $table->uuid('site_id')->index();
            $table->string('name', 255);
            $table->string('main_topic', 255);
            $table->string('primary_keyword', 255);
            $table->json('supporting_keywords')->nullable();
            $table->string('audience', 255)->nullable();
            $table->string('tone', 255)->nullable();
            $table->string('funnel_stage', 64)->nullable();
            $table->unsignedSmallInteger('articles_count')->default(5);
            $table->string('status', 32)->default('draft');
            $table->json('strategy_json')->nullable();
            $table->json('publish_plan_json')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status'], 'content_series_org_status_idx');
            $table->index(['site_id', 'created_at'], 'content_series_site_created_idx');

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('client_sites')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_series');
    }
};
