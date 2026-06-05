<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasUuids;

    public static bool $allowInPlaceMutation = false;

    protected $fillable = [
        'organization_id',
        'subscription_id',
        'payment_intent_id',
        'credit_pack_purchase_id',
        'type',
        'number',
        'status',
        'currency',
        'pricing_mode',
        'subtotal_net',
        'vat_amount',
        'total_gross',
        'subtotal_cents',
        'tax_cents',
        'total_cents',
        'document_type',
        'vat_rate',
        'vat_type',
        'reverse_charge',
        'refund_reference',
        'issued_at',
        'paid_at',
        'refunded_at',
        'billing_company_name',
        'billing_address_line1',
        'billing_address_line2',
        'billing_postal_code',
        'billing_city',
        'billing_country_code',
        'billing_vat_number',
        'billing_kvk_number',
        'pdf_path',
        'pdf_path_previous',
        'pdf_checksum',
        'pdf_status',
        'pdf_error_message',
        'immutable_hash',
        'meta',
        'is_backfilled',
        'backfilled_at',
        'backfill_source',
        'backfill_batch_id',
        'corrected_at',
        'correction_reason',
        'corrected_by_batch_id',
        'replaces_invoice_id',
        'credit_note_for_invoice_id',
    ];

    protected $casts = [
        'subtotal_net' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total_gross' => 'decimal:2',
        'subtotal_cents' => 'integer',
        'tax_cents' => 'integer',
        'total_cents' => 'integer',
        'vat_rate' => 'decimal:2',
        'reverse_charge' => 'boolean',
        'issued_at' => 'datetime',
        'paid_at' => 'datetime',
        'refunded_at' => 'datetime',
        'meta' => 'array',
        'is_backfilled' => 'boolean',
        'backfilled_at' => 'datetime',
        'corrected_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (Invoice $invoice): void {
            if (self::$allowInPlaceMutation) {
                return;
            }

            $immutableFields = [
                'organization_id',
                'subscription_id',
                'payment_intent_id',
                'credit_pack_purchase_id',
                'type',
                'number',
                'currency',
                'subtotal_cents',
                'tax_cents',
                'total_cents',
                'vat_rate',
                'vat_type',
                'reverse_charge',
                'pricing_mode',
                'subtotal_net',
                'vat_amount',
                'total_gross',
                'document_type',
                'issued_at',
                'billing_company_name',
                'billing_address_line1',
                'billing_address_line2',
                'billing_postal_code',
                'billing_city',
                'billing_country_code',
                'billing_vat_number',
                'billing_kvk_number',
                'replaces_invoice_id',
                'credit_note_for_invoice_id',
            ];

            foreach ($immutableFields as $field) {
                if ($invoice->isDirty($field)) {
                    throw new \RuntimeException('Issued invoice is immutable. Create corrections via credit/refund records.');
                }
            }
        });
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function paymentIntent()
    {
        return $this->belongsTo(PaymentIntent::class);
    }

    public function creditPackPurchase()
    {
        return $this->belongsTo(CreditPackPurchase::class);
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function replacedInvoice()
    {
        return $this->belongsTo(self::class, 'replaces_invoice_id');
    }

    public function replacementInvoices()
    {
        return $this->hasMany(self::class, 'replaces_invoice_id');
    }

    public function creditNoteForInvoice()
    {
        return $this->belongsTo(self::class, 'credit_note_for_invoice_id');
    }

    public function creditNotes()
    {
        return $this->hasMany(self::class, 'credit_note_for_invoice_id');
    }
}
