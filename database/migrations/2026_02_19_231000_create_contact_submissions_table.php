<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('company', 190)->nullable();
            $table->string('subject', 190)->nullable();
            $table->text('message');
            $table->string('topic', 120)->nullable();
            $table->string('source_page', 190)->nullable();
            $table->string('cta_label', 190)->nullable();
            $table->string('url', 500)->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('mail_sent_at')->nullable();
            $table->text('mail_error')->nullable();
            $table->timestamps();

            $table->index(['created_at']);
            $table->index(['email']);
            $table->index(['topic']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_submissions');
    }
};

