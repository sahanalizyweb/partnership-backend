<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionSlab extends Model
{
    use HasFactory;

    protected $fillable = ['commission_rule_id', 'min_value', 'max_value', 'rate'];

    public function commissionRule(): BelongsTo
    {
        return $this->belongsTo(CommissionRule::class);
    }
}
