<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email_code_hash')->nullable()->after('email_verified_at');
            $table->timestamp('email_code_expires_at')->nullable()->after('email_code_hash');
            $table->timestamp('email_code_verified_at')->nullable()->after('email_code_expires_at');
            $table->timestamp('email_code_sent_at')->nullable()->after('email_code_verified_at');
            $table->unsignedInteger('email_code_attempts')->default(0)->after('email_code_sent_at');
            $table->timestamp('email_code_last_attempt_at')->nullable()->after('email_code_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'email_code_hash',
                'email_code_expires_at',
                'email_code_verified_at',
                'email_code_sent_at',
                'email_code_attempts',
                'email_code_last_attempt_at',
            ]);
        });
    }
};
