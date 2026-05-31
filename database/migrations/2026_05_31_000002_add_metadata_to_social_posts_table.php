<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('social_posts', 'metadata')) {
            return;
        }

        Schema::table('social_posts', function (Blueprint $table): void {
            $table->json('metadata')->nullable()->after('media');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('social_posts', 'metadata')) {
            return;
        }

        Schema::table('social_posts', function (Blueprint $table): void {
            $table->dropColumn('metadata');
        });
    }
};
