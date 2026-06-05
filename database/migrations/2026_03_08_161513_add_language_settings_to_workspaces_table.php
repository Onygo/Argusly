<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('default_content_language', 5)->default('en')->after('visual_settings');
            $table->json('enabled_content_languages')->nullable()->after('default_content_language');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn([
                'default_content_language',
                'enabled_content_languages',
            ]);
        });
    }
};
