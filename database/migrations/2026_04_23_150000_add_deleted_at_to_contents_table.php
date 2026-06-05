<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            if (! Schema::hasColumn('contents', 'deleted_at')) {
                $table->softDeletes();
                $table->index(['workspace_id', 'deleted_at'], 'contents_workspace_deleted_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            if (Schema::hasColumn('contents', 'deleted_at')) {
                $table->dropIndex('contents_workspace_deleted_idx');
                $table->dropSoftDeletes();
            }
        });
    }
};
