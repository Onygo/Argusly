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
            if (! Schema::hasColumn('notifications', 'target_scope')) {
                $table->string('target_scope', 20)->default('workspace')->after('workspace_id')->index();
            }

            if (! Schema::hasColumn('notifications', 'is_admin_only')) {
                $table->boolean('is_admin_only')->default(false)->after('target_scope')->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table): void {
            if (Schema::hasColumn('notifications', 'is_admin_only')) {
                $table->dropColumn('is_admin_only');
            }

            if (Schema::hasColumn('notifications', 'target_scope')) {
                $table->dropColumn('target_scope');
            }
        });
    }
};

