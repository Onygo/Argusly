<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'subscription_id',
    'provider',
    'provider_invoice_id',
    'provider_payment_id',
    'status',
    'currency',
    'subtotal_amount',
    'tax_amount',
    'total_amount',
    'period_start',
    'period_end',
    'line_items',
    'metadata',
    'issued_at',
    'paid_at',
    'due_at',
])]
class BillingInvoice extends Model
{
    use HasFactory;

    public const STATUSES = ['draft', 'open', 'paid', 'failed', 'void', 'refunded'];

    protected static function booted(): void
    {
        static::creating(function (BillingInvoice $invoice): void {
            $invoice->uuid ??= (string) Str::uuid();
            $invoice->provider ??= 'mollie';
            $invoice->status ??= 'draft';
            $invoice->currency ??= 'EUR';
        });

        static::saving(function (BillingInvoice $invoice): void {
            if (! in_array($invoice->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid billing invoice status [{$invoice->status}].");
            }

            if ($invoice->subscription_id !== null) {
                $subscription = Subscription::query()->find($invoice->subscription_id);

                if (! $subscription || $subscription->account_id !== $invoice->account_id) {
                    throw new InvalidArgumentException('Billing invoice subscription must belong to the same account.');
                }
            }
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    protected function casts(): array
    {
        return [
            'subtotal_amount' => 'integer',
            'tax_amount' => 'integer',
            'total_amount' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'line_items' => 'array',
            'metadata' => 'array',
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
            'due_at' => 'datetime',
        ];
    }
}
