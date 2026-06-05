<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_publish_targets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('content_id')->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->string('target_type', 32)->default('wp');
            $table->string('target_identifier')->nullable();
            $table->string('sync_status', 32)->default('pending');
            $table->timestamp('last_synced_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('content_id')->references('id')->on('contents')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->index(['content_id', 'target_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_publish_targets');
    }
};
