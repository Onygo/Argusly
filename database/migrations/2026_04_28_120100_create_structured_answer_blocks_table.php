<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('structured_answer_blocks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('content_id');
            $table->string('question');
            $table->text('answer');
            $table->json('entities')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();

            $table->foreign('content_id')
                ->references('id')
                ->on('contents')
                ->cascadeOnDelete();

            $table->index(['content_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('structured_answer_blocks');
    }
};
