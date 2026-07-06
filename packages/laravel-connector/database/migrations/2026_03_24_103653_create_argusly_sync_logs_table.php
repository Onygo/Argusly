<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('argusly_sync_logs')) {
            return;
        }

        Schema::create('argusly_sync_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 64);
            $table->string('source_id')->nullable();
            $table->string('event_type', 64);
            $table->string('status', 32);
            $table->text('message')->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['source', 'source_id']);
            $table->index(['event_type', 'status']);
            $table->index('payload_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('argusly_sync_logs');
    }
};
