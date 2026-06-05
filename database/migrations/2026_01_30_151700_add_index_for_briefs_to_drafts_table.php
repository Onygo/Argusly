<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->unique('brief_id', 'drafts_brief_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->dropUnique('drafts_brief_id_unique');
        });
    }
};
