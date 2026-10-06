<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ScopesToRequester;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Product;
use App\Services\AffiliateUrlBuilder;
use App\Services\ShopCatalogService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Admin-managed Product/Service catalog — Admin sets the standard amount + fixed standard
 * commission % once per product; recording a Sale against it then drives the commission
 * automatically (see CommissionCalculationService::generateForSale). Read access is shared
 * with partner-portal users so they can pick a product when recording their own sale.
 */
class ProductController extends Controller
{
    use ScopesToRequester;

    public function index(Request $request)
    {
        $query = Product::with('business:id,name,code');
        $query = $this->scopeBusiness($query, $request);

        foreach (['status', 'type', 'business_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        return $query->orderBy('name')->get();
    }

    /** What the Products form needs to show a service's final page link before saving. */
    public function linkSettings(AffiliateUrlBuilder $urls)
    {
        return ['service_base_url' => $urls->serviceBaseUrl(), 'storefront_url' => $urls->storefrontUrl()];
    }

    /** Live Lizy Mart product list (public /products.json) — used to validate Lizy Mart Page URLs. */
    public function shopCatalog(Request $request, ShopCatalogService $catalog)
    {
        $products = $catalog->products($request->boolean('refresh'));
        abort_if($products === null, 502, 'Could not load the Lizy Mart product list right now — try again shortly.');

        return $products;
    }

    /**
     * "Lizy Mart Page" URL → the stored handle. Must be a real https://lizymart.com/products/…
     * page (a Product's shop page).
     */
    protected function handleFromInput(?string $url): ?string
    {
        $handle = Product::handleFromLizyMartUrl($url);

        abort_if($handle === false, 422, "Lizy Mart Page must be the product's page on Lizy Mart, e.g. https://lizymart.com/products/coconut-scraper");

        return $handle;
    }

    protected const RULES = [
        'type' => 'nullable|in:product,service',
        // Lowercase words joined by dashes, e.g. static-website-development.
        'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
        'lizymart_url' => 'nullable|string|max:2048',
        'page_url' => 'nullable|url|max:2048',
        'description' => 'nullable|string',
        'status' => 'in:active,inactive',
    ];

    /**
     * Works out the slug to store and checks it:
     *  - a Product with a Lizy Mart Page takes its shop handle from that URL;
     *  - otherwise the Slug typed (blank = made from the Name, for a Service);
     *  - unique per business; a Lizy Mart product's handle must exist on the live shop (only
     *    checked when the shop can be reached — never for a Service, which has no shop sync).
     */
    protected function resolveSlug(array $data, ?Product $product, int $businessId, ShopCatalogService $catalog): ?string
    {
        $type = $data['type'] ?? $product?->type ?? 'product';
        $name = $data['name'] ?? $product?->name;

        if ($type === 'product' && filled($data['lizymart_url'] ?? null)) {
            $slug = $this->handleFromInput($data['lizymart_url']);
        } elseif (array_key_exists('slug', $data)) {
            $slug = $data['slug'] ?: ($type === 'service' ? Str::slug($name) : null);
        } elseif (array_key_exists('lizymart_url', $data)) {
            $slug = null; // Lizy Mart Page cleared (older form).
        } else {
            $slug = $product?->slug ?? ($type === 'service' ? Str::slug($name) : null);
        }

        validator(['slug' => $slug], [
            'slug' => Rule::unique('products', 'slug')->where('business_id', $businessId)->ignore($product?->id),
        ], ['slug.unique' => "The slug \"{$slug}\" already exists for this business — change the Slug."])->validate();

        $pageUrl = array_key_exists('page_url', $data) ? $data['page_url'] : $product?->page_url;
        abort_if(
            ! $slug && ! $pageUrl && $product?->affiliateLinks()->exists(),
            422,
            'A Slug or page URL is required — this product/service is used in affiliate links, which open its page.'
        );

        $isLizyMartProduct = $type === 'product' && Business::whereKey($businessId)->value('code') === 'lizymart';
        if ($isLizyMartProduct && $slug && $slug !== $product?->slug && $catalog->handleExists($slug) === false) {
            abort(422, "No product page exists at https://lizymart.com/products/{$slug} — copy the address from the product's page on Lizy Mart.");
        }

        return $slug;
    }

    public function store(Request $request, ShopCatalogService $catalog)
    {
        $user = $request->user();

        $data = $request->validate([
            'business_id' => $user->business_id ? 'nullable' : 'required|exists:businesses,id',
            'name' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'commission_percent' => 'required|numeric|min:0|max:100',
        ] + self::RULES);

        $data['business_id'] = $user->business_id ?: $data['business_id'];
        // Lizyweb sells services; Lizy Mart (and others) sell products.
        $data['type'] ??= Business::whereKey($data['business_id'])->value('code') === 'lizyweb' ? 'service' : 'product';
        $data['slug'] = $this->resolveSlug($data, null, (int) $data['business_id'], $catalog);
        unset($data['lizymart_url']);

        $product = Product::create($data);

        return response()->json($product->load('business:id,name,code'), 201);
    }

    public function show(Product $product)
    {
        return $product->load('business:id,name,code');
    }

    public function update(Request $request, Product $product, ShopCatalogService $catalog)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'amount' => 'sometimes|required|numeric|min:0',
            'commission_percent' => 'sometimes|required|numeric|min:0|max:100',
        ] + self::RULES);

        if (array_key_exists('type', $data) && ! $data['type']) {
            unset($data['type']);
        }
        $data['slug'] = $this->resolveSlug($data, $product, (int) $product->business_id, $catalog);
        unset($data['lizymart_url']);

        $product->update($data);

        return $product->load('business:id,name,code');
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return response()->json(null, 204);
    }
}
