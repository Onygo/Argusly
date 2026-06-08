<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('argusly_settings')) {
            return;
        }

        Schema::create('argusly_settings', function (Blueprint $table) {
            $table->id();
            $table->string('site_key', 128)->nullable();
            $table->string('setting_key', 128);
            $table->json('setting_value')->nullable();
            $table->timestamps();

            $table->unique(['site_key', 'setting_key'], 'pl_settings_site_key_uidx');
            $table->index('setting_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('argusly_settings');
    }
};
