<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    use BelongsToBusiness, HasFactory;

    // Append-only: rows are created only through LedgerPostingService::post().
    public $timestamps = true;

    protected $fillable = [
        'business_id', 'partner_id', 'entry_date', 'transaction_type', 'reference_type',
        'reference_id', 'debit', 'credit', 'running_balance', 'description',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date:Y-m-d',
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'running_balance' => 'decimal:2',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
