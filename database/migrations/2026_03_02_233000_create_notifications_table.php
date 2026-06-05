<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->nullable()->index();
            $table->string('target_scope', 20)->default('workspace')->index();
            $table->boolean('is_admin_only')->default(false)->index();
            $table->foreignId('user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('type', 32)->index();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('cta_label')->nullable();
            $table->string('cta_url')->nullable();
            $table->smallInteger('priority')->default(50);
            $table->timestamp('read_at')->nullable()->index();
            $table->foreignId('created_by_admin_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
            $table->index(['workspace_id', 'type']);
            $table->index(['workspace_id', 'created_at']);
            $table->index(['workspace_id', 'user_id', 'read_at']);
            $table->index(['target_scope', 'type', 'read_at']);
            $table->index(['is_admin_only', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
