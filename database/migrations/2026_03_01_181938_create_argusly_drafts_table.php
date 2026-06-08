<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('argusly_drafts')) {
            return;
        }

        Schema::create('argusly_drafts', function (Blueprint $table) {
            $table->id();
            $table->string('pl_draft_id', 64)->unique();
            $table->string('pl_content_id', 64)->nullable()->index();
            $table->string('pl_brief_id', 64)->nullable();
            $table->string('external_key', 128)->nullable()->index();
            $table->string('title', 512)->nullable();
            $table->string('output_type', 64)->nullable();
            $table->longText('content_html')->nullable();
            $table->json('meta')->nullable();
            $table->json('links')->nullable();

            // Featured image
            $table->string('featured_image_path', 512)->nullable();
            $table->string('featured_image_url', 1024)->nullable();
            $table->string('featured_image_original_url', 1024)->nullable();
            $table->string('featured_image_version', 64)->nullable();

            // OG image
            $table->string('og_image_path', 512)->nullable();
            $table->string('og_image_url', 1024)->nullable();
            $table->string('og_image_original_url', 1024)->nullable();

            $table->enum('status', ['pending', 'ready', 'processed', 'failed'])->default('pending');
            $table->text('last_error')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('argusly_drafts');
    }
};
