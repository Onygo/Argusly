<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('early_access_signups', function (Blueprint $table) {
            $table->id();
            $table->string('full_name', 255);
            $table->string('email', 255);
            $table->string('company_name', 255)->nullable();
            $table->string('website', 500)->nullable();
            $table->text('use_case')->nullable();
            $table->text('notes')->nullable();
            $table->string('source', 120)->nullable();
            $table->string('status', 32)->index();
            $table->text('internal_notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('activated_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('workspace_id')->nullable();
            $table->timestamps();

            $table->index(['email']);
            $table->index(['submitted_at']);
            $table->index(['email', 'status']);
            $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
        });

        Schema::create('early_access_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('early_access_signup_id')->constrained('early_access_signups')->cascadeOnDelete();
            $table->string('email', 255);
            $table->string('token_hash', 64)->unique();
            $table->text('token_encrypted');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['email']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('early_access_invites');
        Schema::dropIfExists('early_access_signups');
    }
};
