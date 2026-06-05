<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->index(['status', 'updated_at'], 'drafts_status_updated_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->dropIndex('drafts_status_updated_at_idx');
        });
    }
};
