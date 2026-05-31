<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->text('objective')->nullable();
            $table->string('status')->default('draft')->index();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'brand_id', 'slug']);
            $table->index(['account_id', 'brand_id', 'status']);
            $table->index(['account_id', 'brand_id', 'start_date', 'end_date'], 'campaigns_scope_date_index');
        });

        Schema::create('campaign_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_asset_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['campaign_id', 'content_asset_id']);
        });

        Schema::create('campaign_topics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['campaign_id', 'topic_id']);
        });

        Schema::create('campaign_signals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('intelligence_signal_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['campaign_id', 'intelligence_signal_id'], 'campaign_signal_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_signals');
        Schema::dropIfExists('campaign_topics');
        Schema::dropIfExists('campaign_assets');
        Schema::dropIfExists('campaigns');
    }
};
