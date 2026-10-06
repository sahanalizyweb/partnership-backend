<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'partner_id', 'document_type_id', 'document_number', 'file_path',
        'issue_date', 'expiry_date', 'verification_status', 'verified_by',
        'verified_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date:Y-m-d',
            'expiry_date' => 'date:Y-m-d',
            'verified_at' => 'datetime',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
