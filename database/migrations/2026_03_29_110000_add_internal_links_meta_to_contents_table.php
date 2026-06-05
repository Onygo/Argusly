<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            if (! Schema::hasColumn('contents', 'internal_links_meta')) {
                $table->json('internal_links_meta')->nullable()->after('current_version_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            if (Schema::hasColumn('contents', 'internal_links_meta')) {
                $table->dropColumn('internal_links_meta');
            }
        });
    }
};
