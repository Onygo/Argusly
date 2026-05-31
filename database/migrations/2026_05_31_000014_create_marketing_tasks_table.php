<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_tasks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('marketing_objective_id')->nullable()->constrained()->nullOnDelete();
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('backlog')->index();
            $table->string('priority')->default('medium')->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'brand_id', 'status'], 'marketing_tasks_scope_status_index');
            $table->index(['account_id', 'brand_id', 'priority'], 'marketing_tasks_scope_priority_index');
            $table->index(['related_type', 'related_id'], 'marketing_tasks_related_index');
            $table->index(['campaign_id', 'status']);
            $table->index(['marketing_objective_id', 'status'], 'marketing_tasks_objective_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_tasks');
    }
};
