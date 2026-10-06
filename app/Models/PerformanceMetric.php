<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceMetric extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id', 'partner_id', 'period', 'leads_received', 'leads_contacted',
        'conversions', 'conversion_rate', 'sales_value', 'assignments_completed',
        'response_time_avg', 'completion_rate', 'avg_rating', 'complaints_count',
        'commission_generated', 'rank',
    ];

    protected function casts(): array
    {
        return [
            'conversion_rate' => 'decimal:2',
            'sales_value' => 'decimal:2',
            'response_time_avg' => 'decimal:2',
            'completion_rate' => 'decimal:2',
            'avg_rating' => 'decimal:2',
            'commission_generated' => 'decimal:2',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
