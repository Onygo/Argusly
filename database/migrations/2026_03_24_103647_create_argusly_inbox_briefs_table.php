<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('argusly_inbox_briefs') || Schema::hasTable('pl_inbox_briefs')) {
            return;
        }

        Schema::create('argusly_inbox_briefs', function (Blueprint $table) {
            $table->id();
            $table->string('site_key', 128);
            $table->string('pl_brief_id', 128);
            $table->string('title', 512)->nullable();
            $table->string('status', 40)->default('received');
            $table->json('brief_payload')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->unique(['site_key', 'pl_brief_id'], 'argusly_inbox_briefs_site_brief_uidx');
            $table->index(['site_key', 'status'], 'argusly_inbox_briefs_site_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('argusly_inbox_briefs');
    }
};
