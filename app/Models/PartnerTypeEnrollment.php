<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One partner can hold many of these simultaneously (e.g. Service + Sales + Affiliate +
 * Reseller at once) — this is what makes Partner != PartnerType. Exactly one row per partner
 * has is_primary=true, mirroring the legacy partners.partner_type_id/partner_category_id
 * (kept untouched for backward compatibility with existing code that reads it directly).
 */
class PartnerTypeEnrollment extends Model
{
    use BelongsToBusiness, HasFactory, LogsActivity;

    protected $fillable = [
        'business_id', 'partner_id', 'partner_type_id', 'partner_category_id',
        'enrollment_code', 'status', 'is_primary', 'commission_rule_id',
        'effective_from', 'effective_to', 'settings', 'activated_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_primary' => 'boolean',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'activated_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function partnerType(): BelongsTo
    {
        return $this->belongsTo(PartnerType::class);
    }

    public function partnerCategory(): BelongsTo
    {
        return $this->belongsTo(PartnerCategory::class);
    }

    public function commissionRule(): BelongsTo
    {
        return $this->belongsTo(CommissionRule::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class, 'partner_type_enrollment_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'partner_type_enrollment_id');
    }

    public function resellerPurchases(): HasMany
    {
        return $this->hasMany(ResellerPurchase::class, 'partner_type_enrollment_id');
    }

    public function resellerProductPrices(): HasMany
    {
        return $this->hasMany(ResellerProductPrice::class);
    }
}
