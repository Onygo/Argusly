<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_insights', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_asset_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->index();
            $table->string('title');
            $table->text('summary');
            $table->string('severity')->default('medium')->index();
            $table->unsignedTinyInteger('impact_score')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('detected_at')->index();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamps();

            $table->index(['account_id', 'brand_id', 'type', 'detected_at'], 'performance_insights_tenant_type_index');
            $table->index(['content_asset_id', 'type', 'resolved_at'], 'performance_insights_asset_type_index');
            $table->index(['campaign_id', 'type', 'resolved_at'], 'performance_insights_campaign_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_insights');
    }
};
