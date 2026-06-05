<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_embeddings', function (Blueprint $table) {
            $table->id();
            $table->uuid('article_id');
            $table->uuid('workspace_id');
            $table->uuid('client_site_id');
            $table->string('embedding_provider');
            $table->string('embedding_model');
            $table->json('embedding_json');
            $table->timestamps();

            $table->unique('article_id');
            $table->index(['workspace_id', 'client_site_id']);
            $table->foreign('article_id')->references('id')->on('drafts')->cascadeOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_embeddings');
    }
};
