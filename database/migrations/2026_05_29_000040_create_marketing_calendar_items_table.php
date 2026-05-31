<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_calendar_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type')->index();
            $table->string('status')->default('planned')->index();
            $table->timestamp('start_at')->index();
            $table->timestamp('end_at')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'brand_id', 'start_at'], 'calendar_items_tenant_start_index');
            $table->index(['related_type', 'related_id'], 'calendar_items_related_index');
            $table->index(['campaign_id', 'start_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_calendar_items');
    }
};
