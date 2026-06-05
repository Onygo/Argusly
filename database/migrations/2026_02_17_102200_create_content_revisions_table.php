<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('content_id')->index();
            $table->uuid('draft_id')->nullable()->index();
            $table->unsignedInteger('revision_number')->default(1);
            $table->string('label')->nullable();
            $table->longText('content_html')->nullable();
            $table->json('meta')->nullable();
            $table->boolean('is_active')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('content_id')->references('id')->on('contents')->cascadeOnDelete();
            $table->foreign('draft_id')->references('id')->on('drafts')->nullOnDelete();
            $table->unique(['content_id', 'revision_number']);
            $table->index(['content_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_revisions');
    }
};
