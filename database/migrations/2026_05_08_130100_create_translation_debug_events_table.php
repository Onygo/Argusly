<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translation_debug_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('trace_id')->index();
            $table->uuid('content_id')->nullable()->index();
            $table->string('locale', 16)->nullable()->index();
            $table->string('event_type', 64)->index();
            $table->string('message', 255);
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_debug_events');
    }
};
