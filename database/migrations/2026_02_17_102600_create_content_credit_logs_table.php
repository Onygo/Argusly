<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_credit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('content_id')->index();
            $table->uuid('draft_id')->nullable()->index();
            $table->uuid('credit_ledger_entry_id')->nullable()->index();
            $table->enum('event', ['initial_generation', 'rewrite', 'revision_creation', 'reserve', 'commit', 'release'])->default('initial_generation');
            $table->unsignedInteger('credits_used')->default(0);
            $table->decimal('mode_multiplier', 5, 2)->default(1.00);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('content_id')->references('id')->on('contents')->cascadeOnDelete();
            $table->foreign('draft_id')->references('id')->on('drafts')->nullOnDelete();
            $table->foreign('credit_ledger_entry_id')->references('id')->on('credit_ledger_entries')->nullOnDelete();
            $table->index(['content_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_credit_logs');
    }
};
