<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'description',
        'quantity',
        'unit_price_cents',
        'unit_price_net',
        'subtotal_cents',
        'line_total_net',
        'tax_rate',
        'tax_cents',
        'vat_amount',
        'total_cents',
        'line_total_gross',
        'meta',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price_cents' => 'integer',
        'unit_price_net' => 'decimal:2',
        'subtotal_cents' => 'integer',
        'line_total_net' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_cents' => 'integer',
        'vat_amount' => 'decimal:2',
        'total_cents' => 'integer',
        'line_total_gross' => 'decimal:2',
        'meta' => 'array',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
