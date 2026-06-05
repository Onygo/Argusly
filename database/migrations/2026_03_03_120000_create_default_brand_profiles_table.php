<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('default_brand_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('tone')->nullable();
            $table->json('style_rules')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('default_brand_profiles');
    }
};
