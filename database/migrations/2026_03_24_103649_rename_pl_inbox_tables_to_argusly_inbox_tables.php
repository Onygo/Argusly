<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pl_inbox_briefs') && ! Schema::hasTable('argusly_inbox_briefs')) {
            Schema::rename('pl_inbox_briefs', 'argusly_inbox_briefs');
        }

        if (Schema::hasTable('pl_inbox_drafts') && ! Schema::hasTable('argusly_inbox_drafts')) {
            Schema::rename('pl_inbox_drafts', 'argusly_inbox_drafts');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('argusly_inbox_briefs') && ! Schema::hasTable('pl_inbox_briefs')) {
            Schema::rename('argusly_inbox_briefs', 'pl_inbox_briefs');
        }

        if (Schema::hasTable('argusly_inbox_drafts') && ! Schema::hasTable('pl_inbox_drafts')) {
            Schema::rename('argusly_inbox_drafts', 'pl_inbox_drafts');
        }
    }
};
