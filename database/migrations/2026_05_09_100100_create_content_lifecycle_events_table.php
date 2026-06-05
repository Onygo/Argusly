<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_lifecycle_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Content reference
            $table->uuid('content_id');
            $table->foreign('content_id')
                ->references('id')
                ->on('contents')
                ->cascadeOnDelete();

            // Stage transition tracking
            $table->string('from_stage', 32)->nullable();
            $table->string('to_stage', 32);

            // Event classification
            // transition, assignment, approval, rejection, comment, due_date_change
            $table->string('event_type', 32);

            // Actor tracking
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // user, system, automation
            $table->string('actor_type', 32)->default('user');

            // Additional context
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            // Indexes for queries
            $table->index(['content_id', 'created_at'], 'lifecycle_events_content_time_idx');
            $table->index(['user_id', 'event_type'], 'lifecycle_events_user_type_idx');
            $table->index(['to_stage', 'created_at'], 'lifecycle_events_stage_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_lifecycle_events');
    }
};
