<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Services\AffiliateUrlBuilder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Product extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id', 'name', 'type', 'slug', 'page_url', 'description', 'amount', 'commission_percent', 'status',
    ];

    public const TYPES = ['product', 'service'];

    protected $appends = ['lizymart_url', 'website_url'];

    /** A Lizyweb service (opens a service page, no stock / shop sync) rather than a Lizy Mart product. */
    public function isService(): bool
    {
        return $this->type === 'service';
    }

    /**
     * The product's handle on the Lizy Mart shop (a Product), or the service's slug on the
     * Lizyweb service page (a Service). A product's handle is checked against the live shop
     * when saved, so a guessed one that 404s is rejected (see ProductController).
     */
    public function shopSlug(): ?string
    {
        return $this->slug ?: null;
    }

    /**
     * The Lizy Mart page URL admin enters, e.g. https://lizymart.com/products/coconut-scraper.
     * Returns just the handle ("coconut-scraper"), false if it isn't a Lizy Mart product page
     * URL, or null when blank.
     */
    public static function handleFromLizyMartUrl(?string $url): string|false|null
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        if (! preg_match('~^https://(www\.)?lizymart\.com/products/([^/?#]+)/?(?:[?#].*)?$~i', $url, $m)) {
            return false;
        }

        return Str::slug(rawurldecode($m[2])) ?: false;
    }

    /** Full Lizy Mart page URL of a Product — what the Products form shows and edits. */
    protected function lizymartUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->slug && ! $this->isService() ? AffiliateUrlBuilder::STOREFRONT_URL.'/products/'.$this->slug : null,
        );
    }

    /** The final public page an affiliate link opens (without ?ref) — read-only, for display. */
    protected function websiteUrl(): Attribute
    {
        return Attribute::make(get: fn () => app(AffiliateUrlBuilder::class)->productUrl($this));
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'commission_percent' => 'decimal:2',
        ];
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function affiliateLinks(): HasMany
    {
        return $this->hasMany(AffiliateLink::class);
    }
}
