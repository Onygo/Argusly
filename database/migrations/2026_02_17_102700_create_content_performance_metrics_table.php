<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_performance_metrics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('content_id')->index();
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('reads')->default(0);
            $table->decimal('read_rate', 5, 2)->default(0.00);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('source_domain')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('content_id')->references('id')->on('contents')->cascadeOnDelete();
            $table->index(['content_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_performance_metrics');
    }
};
