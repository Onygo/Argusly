<?php

namespace App\Console\Commands;

use App\Models\CreditAction;
use App\Models\Draft;
use Illuminate\Console\Command;

class DraftsBackfillCreditCosts extends Command
{
    protected $signature = 'drafts:backfill-credit-costs
        {--key=content.article : Default credit action key}
        {--limit=500 : Max drafts to update}
        {--only-open=1 : Only drafts that are not delivered/acked}';

    protected $description = 'Backfill credit_action_id and credit_cost for existing drafts that are missing it.';

    public function handle(): int
    {
        $key = (string) $this->option('key');
        $limit = (int) $this->option('limit');
        $onlyOpen = (string) $this->option('only-open') === '1';

        $action = CreditAction::query()
            ->where('key', $key)
            ->where('is_active', true)
            ->first();

        if (! $action) {
            $this->error('Credit action not found or inactive: ' . $key);
            return self::FAILURE;
        }

        $q = Draft::query()
            ->where(function ($q) {
                $q->whereNull('credit_action_id')
                    ->orWhereNull('credit_cost');
            });

        if ($onlyOpen) {
            $q->whereNotIn('status', ['delivered', 'acked']);
        }

        $drafts = $q->limit($limit)->get();

        $count = 0;

        foreach ($drafts as $draft) {
            if (! $draft->credit_action_id) {
                $draft->credit_action_id = $action->id;
            }

            if (! $draft->credit_cost) {
                $draft->credit_cost = (int) $action->credits_cost;
            }

            if (! $draft->credit_status) {
                $draft->credit_status = 'pending';
            }

            $draft->save();
            $count++;
        }

        $this->info('Updated drafts: ' . $count);
        return self::SUCCESS;
    }
}
