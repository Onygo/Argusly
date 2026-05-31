<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->json('settings')->nullable()->after('status');
        });

        Schema::table('brands', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('domain');
            $table->string('website_url')->nullable()->after('description');
            $table->string('market')->nullable()->after('website_url');
            $table->string('language')->nullable()->after('market');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->dropColumn(['description', 'website_url', 'market', 'language']);
        });

        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn('settings');
        });
    }
};
