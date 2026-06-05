<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('brief_id')->index();
            $table->uuid('client_site_id')->index();

            $table->string('status')->default('ready'); // ready, delivered, published, revise_requested
            $table->string('title');
            $table->string('output_type')->default('kb_article');
            $table->longText('content_html')->nullable();
            $table->json('meta')->nullable();
            $table->json('links')->nullable();

            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->foreign('brief_id')->references('id')->on('briefs')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->cascadeOnDelete();
        });
    }

    public function down(): void {
        Schema::dropIfExists('drafts');
    }
};
