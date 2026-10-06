<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Partner extends Model
{
    use BelongsToBusiness, HasFactory, LogsActivity;

    protected $fillable = [
        'business_id', 'partner_code', 'partner_type_id', 'partner_category_id',
        'entity_type', 'name', 'email', 'phone', 'alt_phone', 'gstin', 'pan', 'address',
        'primary_location_id', 'location', 'bank_account_name', 'bank_account_number', 'bank_ifsc',
        'bank_name', 'status', 'user_id', 'created_by', 'activated_at',
    ];

    protected function casts(): array
    {
        return ['activated_at' => 'datetime'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function partnerType(): BelongsTo
    {
        return $this->belongsTo(PartnerType::class);
    }

    public function partnerCategory(): BelongsTo
    {
        return $this->belongsTo(PartnerCategory::class);
    }

    public function primaryLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'primary_location_id');
    }

    public function serviceAreas(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'partner_service_areas');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PartnerDocument::class);
    }

    public function agreements(): HasMany
    {
        return $this->hasMany(Agreement::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }

    public function commissionRules(): HasMany
    {
        return $this->hasMany(CommissionRule::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class);
    }

    public function performanceMetrics(): HasMany
    {
        return $this->hasMany(PerformanceMetric::class);
    }

    public function supplies(): HasMany
    {
        return $this->hasMany(Supply::class);
    }

    public function supplierPayments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function resellerPurchases(): HasMany
    {
        return $this->hasMany(ResellerPurchase::class);
    }

    public function resellerPayments(): HasMany
    {
        return $this->hasMany(ResellerPayment::class);
    }

    public function currentBalance(): float
    {
        return (float) $this->ledgerEntries()->latest('id')->value('running_balance') ?? 0.0;
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(PartnerTypeEnrollment::class);
    }

    /** The enrollment mirroring the legacy single partner_type_id — always exactly one per partner. */
    public function primaryEnrollment(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(PartnerTypeEnrollment::class)->where('is_primary', true);
    }

    public function activeEnrollments(): HasMany
    {
        return $this->hasMany(PartnerTypeEnrollment::class)->where('status', 'active');
    }

    /**
     * Every partner role that should drive the Partner Portal (menus + dashboard sections):
     * anything not switched off. A freshly-added role (applied/verified) shows immediately
     * rather than only once separately activated.
     */
    public function portalEnrollments(): HasMany
    {
        return $this->hasMany(PartnerTypeEnrollment::class)->whereNotIn('status', ['suspended', 'rejected', 'inactive']);
    }

    public function affiliateLinks(): HasMany
    {
        return $this->hasMany(AffiliateLink::class);
    }
}
