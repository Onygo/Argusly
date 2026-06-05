<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('content_id');
            $table->uuid('parent_version_id')->nullable();
            $table->enum('type', ['brief', 'draft', 'revision', 'published_snapshot']);
            $table->longText('body')->nullable();
            $table->json('meta')->nullable();
            $table->enum('source', ['wp', 'pl', 'api'])->default('pl');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('content_id')->references('id')->on('contents')->cascadeOnDelete();
            $table->foreign('parent_version_id')->references('id')->on('content_versions')->nullOnDelete();
            $table->index(['content_id', 'type'], 'content_versions_content_type_idx');
            $table->index('parent_version_id', 'content_versions_parent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_versions');
    }
};
