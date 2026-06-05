<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->string('delivery_status', 32)->default('pending')->after('status');
            $table->unsignedInteger('delivery_attempts')->default(0)->after('delivery_status');
            $table->timestamp('delivery_started_at')->nullable()->after('delivery_attempts');
            $table->text('delivery_last_error')->nullable()->after('delivery_started_at');

            $table->index(['delivery_status']);
        });
    }

    public function down(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->dropIndex(['delivery_status']);
            $table->dropColumn([
                'delivery_status',
                'delivery_attempts',
                'delivery_started_at',
                'delivery_last_error',
            ]);
        });
    }
};
