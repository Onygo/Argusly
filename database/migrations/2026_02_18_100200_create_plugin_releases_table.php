<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugin_releases', function (Blueprint $table) {
            $table->id();
            $table->string('version')->unique();
            $table->string('min_wp_version')->nullable();
            $table->string('tested_wp_version')->nullable();
            $table->string('zip_storage_path');
            $table->boolean('is_security_release')->default(false);
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_releases');
    }
};

