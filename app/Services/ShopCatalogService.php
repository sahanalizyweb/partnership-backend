<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The live product list of the public Lizy Mart (Shopify) shop, from its public
 * /products.json feed — so admin links each Product to a page that really exists instead of
 * typing or guessing a URL. Cached briefly; an unreachable shop yields null (unknown), not [].
 */
class ShopCatalogService
{
    /** @return Collection<int, array{handle: string, title: string, url: string}>|null */
    public function products(bool $fresh = false): ?Collection
    {
        if ($fresh) {
            Cache::forget('shop_catalog');
        }

        $products = Cache::remember('shop_catalog', now()->addMinutes(10), function () {
            $all = [];
            for ($page = 1; $page <= 20; $page++) {
                $res = Http::timeout(15)->acceptJson()
                    ->get(AffiliateUrlBuilder::STOREFRONT_URL.'/products.json', ['limit' => 250, 'page' => $page]);
                if (! $res->ok()) {
                    return null;
                }
                $batch = $res->json('products') ?? [];
                foreach ($batch as $p) {
                    $all[] = [
                        'handle' => $p['handle'],
                        'title' => $p['title'],
                        'url' => AffiliateUrlBuilder::STOREFRONT_URL.'/products/'.$p['handle'],
                    ];
                }
                if (count($batch) < 250) {
                    break;
                }
            }

            return $all;
        });

        return $products === null ? null : collect($products)->sortBy('title', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    /** true/false if the shop could be checked, null if it's unreachable right now. */
    public function handleExists(string $handle): ?bool
    {
        try {
            $products = $this->products();
        } catch (\Illuminate\Http\Client\ConnectionException) {
            return null; // e.g. an SSL/cURL error — unreachable means unknown, not "missing".
        }

        return $products === null ? null : $products->contains('handle', $handle);
    }
}
