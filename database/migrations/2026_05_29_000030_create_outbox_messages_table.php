<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->index();
            $table->string('status')->default('pending')->index();
            $table->json('payload');
            $table->timestamp('available_at')->nullable()->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('processed_at')->nullable()->index();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'brand_id', 'status', 'available_at'], 'outbox_tenant_status_available_index');
            $table->index(['account_id', 'type', 'status'], 'outbox_tenant_type_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
