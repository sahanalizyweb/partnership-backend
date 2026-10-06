<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Services\AffiliateUrlBuilder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Method A attribution: a partner's unique link — a Sale created against its code is attributed to them. */
class AffiliateLink extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id', 'partner_id', 'partner_type_enrollment_id', 'code', 'product_id',
        'commission_percent', 'target_url', 'status', 'clicks_count', 'enquiries_count', 'conversions_count', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return ['commission_percent' => 'decimal:2'];
    }

    /**
     * Always rebuilt from the product/service's current page + ref=CODE (see AffiliateUrlBuilder),
     * so a slug or page fix shows up on existing links too. Null while the product isn't linked to
     * a real shop page (never a guessed, 404ing URL); the stored value only when there's no product.
     */
    protected function targetUrl(): Attribute
    {
        return Attribute::make(
            get: fn (?string $stored) => $this->product
                ? app(AffiliateUrlBuilder::class)->build($this->code, $this->product)
                : $stored,
        );
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function partnerTypeEnrollment(): BelongsTo
    {
        return $this->belongsTo(PartnerTypeEnrollment::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function enquiries(): HasMany
    {
        return $this->hasMany(AffiliateEnquiry::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'affiliate_link_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
