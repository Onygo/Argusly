<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('briefs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_site_id')->index();

            $table->string('status')->default('queued'); // queued, generating, ready, error
            $table->float('progress')->default(0);

            $table->string('title');
            $table->string('language', 10)->default('nl');
            $table->string('intent')->nullable();
            $table->string('primary_keyword')->nullable();
            $table->string('audience')->nullable();
            $table->string('output_type')->default('kb_article');
            $table->longText('notes')->nullable();

            $table->json('client_refs')->nullable(); // wp_brief_id etc
            $table->timestamps();

            $table->foreign('client_site_id')->references('id')->on('client_sites')->cascadeOnDelete();
        });
    }

    public function down(): void {
        Schema::dropIfExists('briefs');
    }
};
