<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supply extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id', 'partner_id', 'partner_type_enrollment_id', 'supplier_product_id', 'reference_number', 'product_name',
        'product_details', 'quantity', 'unit_price', 'amount', 'supply_date', 'invoice_number',
        'amount_paid', 'payment_status', 'notes', 'created_by',
    ];

    protected $appends = ['balance_amount'];

    protected function casts(): array
    {
        return [
            'supply_date' => 'date:Y-m-d',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
        ];
    }

    public function getBalanceAmountAttribute(): float
    {
        return round((float) $this->amount - (float) $this->amount_paid, 2);
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

    public function supplierProduct(): BelongsTo
    {
        return $this->belongsTo(SupplierProduct::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function partnerTypeEnrollment(): BelongsTo
    {
        return $this->belongsTo(PartnerTypeEnrollment::class);
    }
}
