<?php

namespace App\Services;

use App\Models\Product;

/**
 * Builds an affiliate tracking URL: the product/service's public page + ref={code}.
 *
 *  - A product/service with its own Website Page URL (page_url) opens that page.
 *  - A Service (Lizyweb) opens AFFILIATE_SERVICE_BASE_URL?slug={slug}.
 *  - A Product (Lizy Mart) opens its real shop page https://lizymart.com/products/{handle} —
 *    only once linked to a real shop product (see Product::shopSlug), never a guess.
 *
 * Always the public customer website — never localhost, APP_URL or the admin app's own URL,
 * since the link is shared with real customers. Returns null when there's no page to open.
 */
class AffiliateUrlBuilder
{
    public const STOREFRONT_URL = 'https://lizymart.com';

    public function storefrontUrl(): string
    {
        return self::STOREFRONT_URL;
    }

    public function serviceBaseUrl(): string
    {
        return rtrim((string) config('services.affiliate.service_base_url'), '?&');
    }

    /** The page the link opens, without the ref. */
    public function productUrl(Product $product): ?string
    {
        if ($product->page_url) {
            return $product->page_url;
        }

        $slug = $product->shopSlug();
        if (! $slug) {
            return null;
        }

        if ($product->isService()) {
            $base = $this->serviceBaseUrl();

            return $base.(str_contains($base, '?') ? '&' : '?').http_build_query(['slug' => $slug]);
        }

        $path = str_replace('{slug}', rawurlencode($slug), config('services.affiliate.product_path'));

        return $this->storefrontUrl().'/'.ltrim($path, '/');
    }

    public function build(string $code, Product $product): ?string
    {
        $url = $this->productUrl($product);

        return $url ? $url.(str_contains($url, '?') ? '&' : '?').http_build_query(['ref' => $code]) : null;
    }
}
