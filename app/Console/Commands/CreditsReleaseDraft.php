<?php

namespace App\Console\Commands;

use App\Models\Draft;
use App\Services\CreditWalletService;
use Illuminate\Console\Command;
use RuntimeException;

class CreditsReleaseDraft extends Command
{
    protected $signature = 'credits:release-draft {draft_id} {--user_id=}';
    protected $description = 'Release reserved credits for a draft (idempotent).';

    public function handle(CreditWalletService $credits): int
    {
        $draftId = (string) $this->argument('draft_id');
        $userId = $this->option('user_id') ? (string) $this->option('user_id') : null;

        $draft = Draft::query()->find($draftId);
        if (! $draft) {
            throw new RuntimeException('Draft not found.');
        }

        $credits->ensureReleasedForDraft($draft, $userId);

        $draft->refresh();

        $this->info('Draft released.');
        $this->table(
            ['draft_id', 'credit_status', 'credit_cost', 'workspace_credit_wallet_id', 'workspace_credit_transaction_id', 'credit_wallet_id', 'credit_ledger_entry_id'],
            [[
                $draft->id,
                (string) $draft->credit_status,
                (string) $draft->credit_cost,
                (string) $draft->workspace_credit_wallet_id,
                (string) $draft->workspace_credit_transaction_id,
                (string) $draft->credit_wallet_id,
                (string) $draft->credit_ledger_entry_id,
            ]]
        );

        return self::SUCCESS;
    }
}
