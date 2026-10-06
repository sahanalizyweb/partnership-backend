<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Lizyweb -> Reseller -> Customer: what a Reseller Partner bought from us to resell. */
class ResellerPurchase extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id', 'partner_id', 'partner_type_enrollment_id', 'business_source_id',
        'product_id', 'reference_number', 'product_name',
        'quantity', 'unit_price', 'amount', 'purchase_date', 'amount_paid', 'payment_status',
        'resale_customer_name', 'resale_amount', 'resale_date', 'resale_status', 'notes',
        'created_by',
    ];

    protected $appends = ['balance_amount', 'margin_amount'];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date:Y-m-d',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'resale_amount' => 'decimal:2',
            'resale_date' => 'date:Y-m-d',
        ];
    }

    public function getBalanceAmountAttribute(): float
    {
        return round((float) $this->amount - (float) $this->amount_paid, 2);
    }

    /** Reseller margin = resale price - purchase price (requirement #17) — null until resold. */
    public function getMarginAmountAttribute(): ?float
    {
        return $this->resale_amount === null ? null : round((float) $this->resale_amount - (float) $this->amount, 2);
    }

    /** Recomputes payment_status from amount vs amount_paid — call after amount_paid changes. */
    public function recalcPaymentStatus(): void
    {
        $paid = (float) $this->amount_paid;
        $total = (float) $this->amount;

        $status = $paid <= 0 ? 'unpaid' : ($paid >= $total ? 'paid' : 'partial');

        if ($status !== $this->payment_status) {
            $this->update(['payment_status' => $status]);
        }
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ResellerPayment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function partnerTypeEnrollment(): BelongsTo
    {
        return $this->belongsTo(PartnerTypeEnrollment::class);
    }

    public function businessSource(): BelongsTo
    {
        return $this->belongsTo(BusinessSource::class);
    }
}
