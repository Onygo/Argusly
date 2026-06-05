<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('site_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_site_id')->index();
            $table->string('token_hash')->unique();
            $table->json('scopes');
            $table->boolean('revoked')->default(false);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->foreign('client_site_id')->references('id')->on('client_sites')->cascadeOnDelete();
        });
    }

    public function down(): void {
        Schema::dropIfExists('site_tokens');
    }
};
