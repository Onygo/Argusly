<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_tokens', function (Blueprint $table) {
            if (! Schema::hasColumn('site_tokens', 'token_encrypted')) {
                $table->text('token_encrypted')->nullable()->after('token_hash');
            }
        });
    }

    public function down(): void
    {
        Schema::table('site_tokens', function (Blueprint $table) {
            if (Schema::hasColumn('site_tokens', 'token_encrypted')) {
                $table->dropColumn('token_encrypted');
            }
        });
    }
};
