<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table): void {
            if (! Schema::hasColumn('notifications', 'dedupe_key')) {
                $table->string('dedupe_key')->nullable()->after('created_by_admin_id');
            }

            if (! Schema::hasColumn('notifications', 'dedupe_scope')) {
                $table->string('dedupe_scope', 64)->nullable()->after('dedupe_key');
            }

            $table->unique(['dedupe_scope', 'dedupe_key'], 'notifications_dedupe_scope_key_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropUnique('notifications_dedupe_scope_key_unique');

            if (Schema::hasColumn('notifications', 'dedupe_scope')) {
                $table->dropColumn('dedupe_scope');
            }

            if (Schema::hasColumn('notifications', 'dedupe_key')) {
                $table->dropColumn('dedupe_key');
            }
        });
    }
};
