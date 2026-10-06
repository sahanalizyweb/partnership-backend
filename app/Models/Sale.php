<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Sale extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id', 'partner_id', 'partner_type_enrollment_id', 'business_source_id',
        'attribution_method', 'sale_type', 'referral_id', 'affiliate_link_id', 'product_id', 'product_name',
        'customer_name', 'customer_phone', 'unit_price', 'quantity', 'sale_amount', 'expected_amount', 'commission_percent',
        'sale_date', 'status', 'payment_status', 'amount_paid', 'payment_method',
        'reference_number', 'external_order_id', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'sale_date' => 'date:Y-m-d',
            'unit_price' => 'decimal:2',
            'sale_amount' => 'decimal:2',
            'commission_percent' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'expected_amount' => 'decimal:2',
        ];
    }

    public function isReferral(): bool
    {
        return $this->sale_type === 'referral';
    }

    /** Payment status follows the amount the customer has paid against the sale total. */
    public static function paymentStatusFor(float $amountPaid, float $total): string
    {
        if ($amountPaid <= 0) {
            return 'unpaid';
        }

        return $amountPaid + 0.005 >= $total ? 'paid' : 'partial';
    }

    /**
     * Whether this sale's commission may become payable. Sales without payment tracking
     * (recorded before it existed, and referrals) keep their existing behaviour.
     */
    public function isCustomerFullyPaid(): bool
    {
        return $this->payment_status === null || $this->payment_status === 'paid';
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** On a sale admin recorded: the Referral it came from. */
    public function referral(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'referral_id');
    }

    /** On a referral: the actual Sale admin recorded for it (at most one). */
    public function linkedSale(): HasOne
    {
        return $this->hasOne(Sale::class, 'referral_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function commission(): HasOne
    {
        return $this->hasOne(Commission::class);
    }

    public function partnerTypeEnrollment(): BelongsTo
    {
        return $this->belongsTo(PartnerTypeEnrollment::class);
    }

    public function businessSource(): BelongsTo
    {
        return $this->belongsTo(BusinessSource::class);
    }

    public function affiliateLink(): BelongsTo
    {
        return $this->belongsTo(AffiliateLink::class);
    }
}
