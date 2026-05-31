<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type')->index();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamp('processed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id'], 'domain_events_subject_index');
            $table->index(['account_id', 'brand_id', 'occurred_at'], 'domain_events_tenant_occurred_index');
            $table->index(['account_id', 'event_type', 'occurred_at'], 'domain_events_tenant_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_events');
    }
};
