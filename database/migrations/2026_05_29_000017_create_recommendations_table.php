<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('signal_id')->nullable()->constrained('intelligence_signals')->nullOnDelete();
            $table->string('title');
            $table->text('summary');
            $table->text('recommended_action');
            $table->unsignedTinyInteger('impact_score')->nullable();
            $table->unsignedTinyInteger('confidence_score')->nullable();
            $table->string('status')->default('new')->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['account_id', 'signal_id', 'title'], 'recommendations_account_signal_title_unique');
            $table->index(['account_id', 'brand_id', 'status', 'created_at']);
            $table->index(['signal_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendations');
    }
};
