<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('intelligence_signals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source')->index();
            $table->string('type')->index();
            $table->string('title');
            $table->text('summary');
            $table->unsignedTinyInteger('impact_score')->nullable();
            $table->unsignedTinyInteger('confidence_score')->nullable();
            $table->string('status')->default('new')->index();
            $table->text('recommended_action')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('detected_at')->index();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'status', 'detected_at']);
            $table->index(['brand_id', 'status', 'detected_at']);
            $table->index(['account_id', 'type', 'detected_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('intelligence_signals');
    }
};
