<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('publishlayer_connector_heartbeats')) {
            return;
        }

        Schema::create('publishlayer_connector_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->string('site_key', 128)->unique();
            $table->timestamp('last_seen_at');
            $table->string('source', 32)->default('unknown');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publishlayer_connector_heartbeats');
    }
};
