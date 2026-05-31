<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name');
            $table->string('native_name');
            $table->boolean('is_ui_enabled')->default(false)->index();
            $table->boolean('is_content_enabled')->default(false)->index();
            $table->boolean('is_default')->default(false)->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('locale', 16)->nullable()->after('password')->index();
        });

        Schema::table('accounts', function (Blueprint $table): void {
            $table->string('default_locale', 16)->default('en')->after('status')->index();
            $table->string('default_content_language', 16)->default('en')->after('default_locale')->index();
        });

        Schema::table('brands', function (Blueprint $table): void {
            $table->string('default_content_language', 16)->default('en')->after('language')->index();
            $table->json('enabled_content_languages')->nullable()->after('default_content_language');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->dropColumn(['default_content_language', 'enabled_content_languages']);
        });

        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn(['default_locale', 'default_content_language']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('locale');
        });

        Schema::dropIfExists('languages');
    }
};
