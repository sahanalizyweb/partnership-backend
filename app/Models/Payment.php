<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id', 'partner_id', 'assignment_id', 'commission_id', 'payment_type', 'direction', 'amount',
        'reference_number', 'payment_date', 'payment_method', 'status',
        'related_settlement_id', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date:Y-m-d',
            'amount' => 'decimal:2',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function commission(): BelongsTo
    {
        return $this->belongsTo(Commission::class);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class, 'related_settlement_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
