<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('argusly_inbox_drafts') || Schema::hasTable('pl_inbox_drafts')) {
            return;
        }

        Schema::create('argusly_inbox_drafts', function (Blueprint $table) {
            $table->id();
            $table->string('site_key', 128);
            $table->string('pl_draft_id', 128);
            $table->string('pl_brief_id', 128)->nullable();
            $table->string('title', 512)->nullable();
            $table->string('slug', 191)->nullable()->index();
            $table->longText('body_markdown')->nullable();
            $table->longText('body_html')->nullable();
            $table->text('excerpt')->nullable();
            $table->text('featured_image_url')->nullable();
            $table->text('featured_image_path')->nullable();
            $table->string('status', 40)->default('draft');
            $table->json('payload')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['site_key', 'pl_draft_id'], 'argusly_inbox_drafts_site_draft_uidx');
            $table->index(['site_key', 'status'], 'argusly_inbox_drafts_site_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('argusly_inbox_drafts');
    }
};
