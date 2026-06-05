<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_wallets', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Let op: kies 1 scope. Hier: wallet per client_site.
            // Als je later per account wilt, maak dit account_id en migreer client_site_id naar account_id.
            $table->uuid('client_site_id')->unique();

            // cache velden, zodat je niet elke keer ledger hoeft te sommeren
            $table->integer('balance_cached')->default(0);   // totaal beschikbaar (kan negatief als je dat toestaat, meestal niet)
            $table->integer('reserved_cached')->default(0);  // gereserveerd voor lopende jobs

            $table->timestamps();

            $table->index('client_site_id');

            $table->foreign('client_site_id')
                ->references('id')
                ->on('client_sites')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_wallets');
    }
};
