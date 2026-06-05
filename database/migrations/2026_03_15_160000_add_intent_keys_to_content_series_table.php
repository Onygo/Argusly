<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_series', function (Blueprint $table): void {
            if (! Schema::hasColumn('content_series', 'intent_keys')) {
                $table->json('intent_keys')->nullable()->after('supporting_keywords');
            }
        });
    }

    public function down(): void
    {
        Schema::table('content_series', function (Blueprint $table): void {
            if (Schema::hasColumn('content_series', 'intent_keys')) {
                $table->dropColumn('intent_keys');
            }
        });
    }
};
