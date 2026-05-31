<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_stages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('position')->default(0);
            $table->string('status')->default('active')->index();
            $table->timestamps();

            $table->unique(['campaign_id', 'name']);
            $table->index(['account_id', 'brand_id', 'campaign_id', 'position'], 'campaign_stages_scope_position_index');
        });

        Schema::create('campaign_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_stage_id')->nullable()->constrained('campaign_stages')->nullOnDelete();
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('active')->index();
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'brand_id', 'campaign_id', 'status'], 'campaign_items_scope_status_index');
            $table->index(['campaign_stage_id', 'position']);
            $table->index(['related_type', 'related_id'], 'campaign_items_related_index');
            $table->index(['assigned_to', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_items');
        Schema::dropIfExists('campaign_stages');
    }
};
