<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            if (! Schema::hasColumn('contents', 'aeo_score')) {
                $table->unsignedTinyInteger('aeo_score')->nullable()->after('actual_word_count');
            }

            if (! Schema::hasColumn('contents', 'aeo_breakdown')) {
                $table->json('aeo_breakdown')->nullable()->after('aeo_score');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $drops = [];

            if (Schema::hasColumn('contents', 'aeo_breakdown')) {
                $drops[] = 'aeo_breakdown';
            }

            if (Schema::hasColumn('contents', 'aeo_score')) {
                $drops[] = 'aeo_score';
            }

            if ($drops !== []) {
                $table->dropColumn($drops);
            }
        });
    }
};
