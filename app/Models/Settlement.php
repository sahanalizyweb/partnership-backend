<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Settlement extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id', 'partner_id', 'settlement_number', 'period_start', 'period_end',
        'total_commission', 'total_commission_owed_by_partner', 'total_advances', 'total_refunds',
        'total_adjustments', 'net_payable', 'status', 'approved_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'approved_at' => 'datetime',
            'total_commission' => 'decimal:2',
            'total_commission_owed_by_partner' => 'decimal:2',
            'total_advances' => 'decimal:2',
            'total_refunds' => 'decimal:2',
            'total_adjustments' => 'decimal:2',
            'net_payable' => 'decimal:2',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SettlementItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'related_settlement_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
