<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'admin_role')) {
                $table->string('admin_role', 32)->nullable()->after('is_admin');
            }
        });

        DB::table('users')
            ->select(['id', 'is_admin', 'admin_role'])
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    if (trim((string) ($row->admin_role ?? '')) !== '') {
                        continue;
                    }

                    DB::table('users')
                        ->where('id', $row->id)
                        ->update([
                            'admin_role' => (bool) $row->is_admin ? 'superadmin' : 'user',
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'admin_role')) {
                $table->dropColumn('admin_role');
            }
        });
    }
};

