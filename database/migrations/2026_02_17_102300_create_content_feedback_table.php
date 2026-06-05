<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_feedback', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('content_id')->index();
            $table->uuid('revision_id')->nullable()->index();
            $table->enum('type', ['editor', 'client', 'system'])->default('editor');
            $table->text('message');
            $table->json('context')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('content_id')->references('id')->on('contents')->cascadeOnDelete();
            $table->foreign('revision_id')->references('id')->on('content_revisions')->nullOnDelete();
            $table->index(['content_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_feedback');
    }
};
