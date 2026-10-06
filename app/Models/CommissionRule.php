<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommissionRule extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id', 'name', 'partner_id', 'partner_category_id', 'partner_type_id',
        'rule_type', 'value', 'product_ref', 'effective_from', 'effective_to', 'status',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'value' => 'decimal:4',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function partnerCategory(): BelongsTo
    {
        return $this->belongsTo(PartnerCategory::class);
    }

    public function partnerType(): BelongsTo
    {
        return $this->belongsTo(PartnerType::class);
    }

    public function slabs(): HasMany
    {
        return $this->hasMany(CommissionSlab::class);
    }
}
