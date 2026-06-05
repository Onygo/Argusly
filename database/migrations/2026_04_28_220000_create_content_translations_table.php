<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_translations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('content_id');
            $table->string('target_locale', 16);
            $table->uuid('target_content_id')->nullable();
            $table->string('status', 32);
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->string('job_id', 128)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['content_id', 'target_locale'], 'content_translations_content_locale_unique');
            $table->index(['content_id', 'status'], 'content_translations_content_status_idx');
            $table->index(['status'], 'content_translations_status_idx');
            $table->index(['target_locale'], 'content_translations_target_locale_idx');

            $table->foreign('content_id', 'content_translations_content_id_fk')
                ->references('id')
                ->on('contents')
                ->cascadeOnDelete();

            $table->foreign('target_content_id', 'content_translations_target_content_id_fk')
                ->references('id')
                ->on('contents')
                ->nullOnDelete();

            $table->foreign('requested_by_user_id', 'content_translations_requested_by_user_id_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_translations');
    }
};
