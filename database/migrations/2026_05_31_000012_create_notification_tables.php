<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type')->index();
            $table->string('channel')->default('in_app')->index();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'account_id', 'brand_id', 'type', 'channel'], 'notification_preferences_unique_scope');
            $table->index(['account_id', 'brand_id', 'type', 'channel']);
        });

        Schema::create('notification_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->index();
            $table->string('channel')->default('in_app')->index();
            $table->string('title');
            $table->text('body');
            $table->json('payload')->nullable();
            $table->timestamp('delivered_at')->nullable()->index();
            $table->timestamp('read_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'account_id', 'brand_id', 'read_at'], 'notification_events_user_scope_read_index');
            $table->index(['account_id', 'brand_id', 'type', 'created_at']);
            $table->unique(['user_id', 'domain_event_id', 'type', 'channel'], 'notification_events_domain_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_events');
        Schema::dropIfExists('notification_preferences');
    }
};
