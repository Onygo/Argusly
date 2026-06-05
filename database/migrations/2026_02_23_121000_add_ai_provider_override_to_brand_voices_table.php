<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_voices', function (Blueprint $table): void {
            if (! Schema::hasColumn('brand_voices', 'ai_provider_override')) {
                $table->string('ai_provider_override', 32)->nullable()->after('is_default');
            }
        });
    }

    public function down(): void
    {
        Schema::table('brand_voices', function (Blueprint $table): void {
            if (Schema::hasColumn('brand_voices', 'ai_provider_override')) {
                $table->dropColumn('ai_provider_override');
            }
        });
    }
};
