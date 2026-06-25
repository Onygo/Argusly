<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_marketing_connections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->string('name', 120);
            $table->string('provider', 40)->index();
            $table->string('status', 32)->default('active')->index();
            $table->json('config')->nullable();
            $table->text('credentials')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'name'], 'email_marketing_connections_workspace_name_unique');
            $table->index(['workspace_id', 'provider'], 'email_marketing_connections_workspace_provider_idx');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_marketing_connections');
    }
};
