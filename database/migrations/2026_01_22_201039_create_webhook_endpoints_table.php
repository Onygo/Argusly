<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_site_id')->index();

            $table->string('event_type')->default('draft.ready');
            $table->string('url');
            $table->string('signing_method')->default('hmac_sha256');
            $table->string('secret'); // store encrypted later
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('client_site_id')->references('id')->on('client_sites')->cascadeOnDelete();
        });
    }

    public function down(): void {
        Schema::dropIfExists('webhook_endpoints');
    }
};
