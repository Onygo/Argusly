<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_entities', function (Blueprint $table) {
            $table->id();
            $table->uuid('article_id');
            $table->string('entity');
            $table->enum('entity_type', ['primary', 'secondary']);
            $table->decimal('confidence', 4, 2)->default(0.00);
            $table->timestamps();

            $table->index(['article_id', 'entity']);
            $table->foreign('article_id')->references('id')->on('drafts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_entities');
    }
};
