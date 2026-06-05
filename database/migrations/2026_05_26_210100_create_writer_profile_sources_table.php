<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('writer_profile_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('writer_profile_id')->index();
            $table->uuid('content_id')->nullable()->index();
            $table->uuid('uploaded_file_id')->nullable()->index();
            $table->string('title')->nullable();
            $table->longText('source_text')->nullable();
            $table->string('source_url')->nullable();
            $table->string('language', 16)->nullable()->index();
            $table->unsignedInteger('word_count')->default(0);
            $table->timestamp('analyzed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('writer_profile_id')->references('id')->on('writer_profiles')->cascadeOnDelete();
            $table->foreign('content_id')->references('id')->on('contents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('writer_profile_sources');
    }
};
