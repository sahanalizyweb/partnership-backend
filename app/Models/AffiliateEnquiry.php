<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer enquiry credited to an affiliate partner — from the website through the partner's
 * ?ref= link (source 'link') or entered by hand (source 'manual'). Converting it records a Sale,
 * which carries the commission (see SaleController::store, affiliate_enquiry_id).
 */
class AffiliateEnquiry extends Model
{
    use BelongsToBusiness, HasFactory;

    public const STATUSES = ['new', 'contacted', 'converted', 'lost'];

    protected $fillable = [
        'business_id', 'affiliate_link_id', 'partner_id', 'product_id', 'service_slug',
        'customer_name', 'customer_phone', 'customer_email', 'message', 'source_page_url',
        'website_enquiry_id', 'source', 'status', 'enquiry_date', 'sale_id', 'notes',
    ];

    protected function casts(): array
    {
        return ['enquiry_date' => 'date:Y-m-d'];
    }

    /**
     * Phone numbers are stored as plain digits so duplicates match however typed: an Indian
     * number's +91 / 91 / 0 prefix is dropped ("+91 98765-43210" → "9876543210").
     */
    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        return match (true) {
            strlen($digits) === 12 && str_starts_with($digits, '91') => substr($digits, 2),
            strlen($digits) === 11 && str_starts_with($digits, '0') => substr($digits, 1),
            default => $digits,
        };
    }

    /** A partner may change or delete an enquiry they entered by hand until admin acts on it. */
    public function isEditableByPartner(): bool
    {
        return $this->source === 'manual' && $this->status === 'new';
    }

    public function affiliateLink(): BelongsTo
    {
        return $this->belongsTo(AffiliateLink::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
