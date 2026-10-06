<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPayment extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id', 'partner_id', 'supply_id', 'amount', 'payment_date',
        'payment_method', 'reference_number', 'notes', 'created_by',
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

    public function supply(): BelongsTo
    {
        return $this->belongsTo(Supply::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
