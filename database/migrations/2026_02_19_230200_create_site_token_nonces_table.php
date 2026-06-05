<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_token_nonces', function (Blueprint $table) {
            $table->id();
            $table->uuid('site_token_id');
            $table->string('nonce', 120);
            $table->timestamp('used_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['site_token_id', 'nonce']);
            $table->index(['expires_at']);
            $table->foreign('site_token_id')->references('id')->on('site_tokens')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_token_nonces');
    }
};
