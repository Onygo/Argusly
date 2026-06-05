<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workspaces')) {
            return;
        }

        Schema::table('workspaces', function (Blueprint $table): void {
            if (! Schema::hasColumn('workspaces', 'display_name')) {
                $table->string('display_name')->default('')->after('name');
            }
        });

        DB::table('workspaces')
            ->select(['id', 'name', 'display_name'])
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $displayName = trim((string) ($row->display_name ?? ''));
                    if ($displayName !== '') {
                        continue;
                    }

                    DB::table('workspaces')
                        ->where('id', $row->id)
                        ->update([
                            'display_name' => (string) ($row->name ?? ''),
                        ]);
                }
            }, 'id');
    }

    public function down(): void
    {
        if (! Schema::hasTable('workspaces')) {
            return;
        }

        Schema::table('workspaces', function (Blueprint $table): void {
            if (Schema::hasColumn('workspaces', 'display_name')) {
                $table->dropColumn('display_name');
            }
        });
    }
};

