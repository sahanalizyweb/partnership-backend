<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Per-reseller-enrollment, per-product purchase/recommended-selling pricing (requirement #19). */
class ResellerProductPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'partner_type_enrollment_id', 'product_id', 'purchase_price', 'recommended_selling_price', 'status',
    ];

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:2',
            'recommended_selling_price' => 'decimal:2',
        ];
    }

    public function partnerTypeEnrollment(): BelongsTo
    {
        return $this->belongsTo(PartnerTypeEnrollment::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
