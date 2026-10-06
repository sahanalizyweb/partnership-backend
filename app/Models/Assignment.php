<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assignment extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id', 'partner_id', 'partner_type_enrollment_id', 'business_source_id',
        'assignment_type', 'reference_type', 'reference_id',
        'reference_number', 'title', 'description', 'customer_name', 'customer_phone',
        'delivery_address', 'location_id', 'location', 'partner_category_id', 'product_id', 'assigned_date',
        'due_date', 'status', 'accepted_at', 'completed_at', 'parent_assignment_id',
        'transaction_amount', 'commission_percent', 'payment_direction', 'failure_reason',
        'delivery_proof', 'created_by',
    ];

    /** Exposed to the frontend so it can hide response actions a partner isn't allowed to take. */
    protected $appends = ['was_submitted_by_partner'];

    protected function casts(): array
    {
        return [
            'assigned_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'accepted_at' => 'datetime',
            'completed_at' => 'datetime',
            'transaction_amount' => 'decimal:2',
            'commission_percent' => 'decimal:2',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The structured Location (via location_id). Not named location() — that would clash with
     * the free-text `location` column and blank it out in JSON whenever the relation is loaded.
     */
    public function locationArea(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function partnerCategory(): BelongsTo
    {
        return $this->belongsTo(PartnerCategory::class);
    }

    public function parentAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'parent_assignment_id');
    }

    public function reassignments(): HasMany
    {
        return $this->hasMany(Assignment::class, 'parent_assignment_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(AssignmentHistory::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }

    public function partnerTypeEnrollment(): BelongsTo
    {
        return $this->belongsTo(PartnerTypeEnrollment::class);
    }

    public function businessSource(): BelongsTo
    {
        return $this->belongsTo(BusinessSource::class);
    }

    /** True if this assignment was self-submitted by a partner-portal account rather than admin staff. */
    protected function wasSubmittedByPartner(): Attribute
    {
        return Attribute::make(
            get: fn () => (bool) $this->createdBy?->partner_id,
        );
    }
}
