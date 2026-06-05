<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id')->unique();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->uuid('workspace_id')->nullable()->index();
            $table->string('phase', 40)->index()->default('registered');
            $table->string('intent', 60)->nullable();
            $table->dateTime('registered_at');
            $table->dateTime('verified_at')->nullable();
            $table->dateTime('first_login_at')->nullable();
            $table->dateTime('first_value_at')->nullable();
            $table->dateTime('last_activity_at')->nullable();
            $table->dateTime('last_email_sent_at')->nullable();
            $table->json('emails_sent_json')->nullable();
            $table->json('completed_steps_json')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_states');
    }
};

