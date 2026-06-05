<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('briefs')) {
            Schema::table('briefs', function (Blueprint $table): void {
                if (! Schema::hasColumn('briefs', 'audience_details')) {
                    $table->text('audience_details')->nullable()->after('audience');
                }
            });

            if (DB::getDriverName() === 'mysql') {
                DB::statement("ALTER TABLE `briefs` MODIFY `source` VARCHAR(100) NULL");
            }
        }

        if (Schema::hasTable('content_versions') && DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `content_versions` MODIFY `source` VARCHAR(100) NOT NULL DEFAULT 'pl'");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('content_versions') && DB::getDriverName() === 'mysql') {
            DB::table('content_versions')
                ->whereNotIn('source', ['wp', 'pl', 'api'])
                ->update(['source' => 'pl']);

            DB::statement("ALTER TABLE `content_versions` MODIFY `source` ENUM('wp','pl','api') NOT NULL DEFAULT 'pl'");
        }

        if (Schema::hasTable('briefs')) {
            if (DB::getDriverName() === 'mysql') {
                DB::statement("ALTER TABLE `briefs` MODIFY `source` VARCHAR(32) NULL");
            }

            Schema::table('briefs', function (Blueprint $table): void {
                if (Schema::hasColumn('briefs', 'audience_details')) {
                    $table->dropColumn('audience_details');
                }
            });
        }
    }
};
