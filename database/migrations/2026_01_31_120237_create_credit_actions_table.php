<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('key')->unique();              // bijv: content.article
            $table->string('category');                   // content, rewrite, translate, video
            $table->unsignedInteger('credits_cost');      // vaste cost per output type

            $table->string('label_nl');
            $table->string('label_en');

            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['category', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_actions');
    }
};
