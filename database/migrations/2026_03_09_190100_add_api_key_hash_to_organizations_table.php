<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            if (! Schema::hasColumn('organizations', 'api_key_hash')) {
                $table->string('api_key_hash', 64)->nullable()->after('api_key_encrypted');
                $table->index('api_key_hash', 'organizations_api_key_hash_idx');
            }
        });

        DB::table('organizations')
            ->whereNotNull('api_key_encrypted')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $encrypted = trim((string) ($row->api_key_encrypted ?? ''));
                    if ($encrypted === '') {
                        continue;
                    }

                    try {
                        $plain = trim((string) Crypt::decryptString($encrypted));
                    } catch (\Throwable) {
                        continue;
                    }

                    if ($plain === '') {
                        continue;
                    }

                    DB::table('organizations')
                        ->where('id', $row->id)
                        ->update([
                            'api_key_hash' => hash('sha256', $plain),
                            'updated_at' => $row->updated_at ?? now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            if (Schema::hasColumn('organizations', 'api_key_hash')) {
                $table->dropIndex('organizations_api_key_hash_idx');
                $table->dropColumn('api_key_hash');
            }
        });
    }
};

