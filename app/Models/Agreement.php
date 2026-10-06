<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agreement extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id', 'partner_id', 'agreement_number', 'agreement_type',
        'start_date', 'end_date', 'payment_terms', 'responsibilities',
        'document_path', 'status', 'version', 'parent_agreement_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function parentAgreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class, 'parent_agreement_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(Agreement::class, 'parent_agreement_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
