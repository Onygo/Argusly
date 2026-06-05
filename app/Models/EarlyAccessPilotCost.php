<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EarlyAccessPilotCost extends Model
{
    public const CATEGORY_ONBOARDING = 'onboarding';
    public const CATEGORY_SUPPORT = 'support';
    public const CATEGORY_IMPLEMENTATION = 'implementation';
    public const CATEGORY_CREDIT_GRANT = 'credit_grant';
    public const CATEGORY_OTHER = 'other';

    protected $fillable = [
        'early_access_signup_id',
        'category',
        'description',
        'amount_cents',
        'currency',
        'incurred_on',
        'created_by',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'incurred_on' => 'date',
    ];

    public static function categoryOptions(): array
    {
        return [
            self::CATEGORY_ONBOARDING => 'Onboarding',
            self::CATEGORY_SUPPORT => 'Support',
            self::CATEGORY_IMPLEMENTATION => 'Implementation',
            self::CATEGORY_CREDIT_GRANT => 'Credit grant',
            self::CATEGORY_OTHER => 'Other',
        ];
    }

    public function signup()
    {
        return $this->belongsTo(EarlyAccessSignup::class, 'early_access_signup_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
