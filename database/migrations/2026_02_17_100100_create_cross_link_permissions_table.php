<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cross_link_permissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('from_workspace_id');
            $table->uuid('to_workspace_id');
            $table->enum('status', ['pending', 'approved', 'revoked'])->default('pending');
            $table->enum('relationship_type', ['same_brand', 'partner', 'franchise', 'publisher_pool']);
            $table->enum('rel_attribute', ['follow', 'nofollow'])->default('follow');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['from_workspace_id', 'to_workspace_id']);
            $table->foreign('from_workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('to_workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->index(['from_workspace_id', 'status']);
            $table->index(['to_workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cross_link_permissions');
    }
};
