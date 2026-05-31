<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_workspaces', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('active')->index();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'brand_id', 'status'], 'marketing_workspaces_scope_status_index');
        });

        Schema::create('marketing_objectives', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type')->index();
            $table->string('status')->default('active')->index();
            $table->decimal('target_value', 14, 2)->nullable();
            $table->decimal('current_value', 14, 2)->nullable();
            $table->string('unit')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'brand_id', 'status'], 'marketing_objectives_scope_status_index');
            $table->index(['account_id', 'brand_id', 'type'], 'marketing_objectives_scope_type_index');
            $table->index(['campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_objectives');
        Schema::dropIfExists('marketing_workspaces');
    }
};
