<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ScopesToRequester;
use App\Http\Controllers\Controller;
use App\Models\AffiliateLink;
use App\Models\Partner;
use App\Models\Product;
use App\Services\AffiliateTrackingService;
use App\Services\AffiliateUrlBuilder;
use App\Services\CommissionCalculationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Method A attribution (requirement #8): a partner's unique affiliate/referral link. A Sale
 * created against a link's code (see SaleController::store) is attributed to that partner
 * automatically, as opposed to Method B (manual admin attribution, requirement #9).
 */
class AffiliateLinkController extends Controller
{
    use ScopesToRequester;

    public function index(Request $request)
    {
        $query = AffiliateLink::with(['partner', 'partnerTypeEnrollment.partnerType', 'product', 'business:id,name,code']);
        $query = $this->scopeBusiness($query, $request);
        $query = $this->scopePartner($query, $request);

        if ($request->filled('partner_id')) {
            $query->where('partner_id', $request->integer('partner_id'));
        }

        return $query->orderByDesc('id')->paginate($request->integer('per_page', 20));
    }

    /**
     * What the Create form shows once Partner + Product are picked: a fresh unique code, the
     * auto-generated tracking URL for it, and the applicable commission % for admin to verify.
     */
    public function preview(Request $request, CommissionCalculationService $calculator)
    {
        $data = $request->validate([
            'partner_id' => 'required|exists:partners,id',
            'product_id' => 'required|exists:products,id',
        ]);

        [$partner, $product] = $this->partnerAndProduct($request, $data);
        $code = $this->generateUniqueCode($partner, $product);
        [$rate, $source] = $this->resolveCommissionPercent($calculator, $partner, $product);

        return [
            'code' => $code,
            'target_url' => $this->buildTargetUrl($code, $product),
            'commission_percent' => $rate,
            'commission_source' => $source,
        ];
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'partner_id' => 'required|exists:partners,id',
            'partner_type_enrollment_id' => 'nullable|exists:partner_type_enrollments,id',
            'product_id' => 'required|exists:products,id',
            // Code comes from preview(); re-checked here so two admins can't claim the same one.
            'code' => ['required', 'string', 'max:100', 'regex:/^[A-Z0-9-]+$/', 'unique:affiliate_links,code'],
            'commission_percent' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string',
        ] + self::COUNT_RULES, [
            'code.unique' => 'This affiliate code was just taken — reselect the product to generate a new one.',
        ]);

        [$partner, $product] = $this->partnerAndProduct($request, $data);

        if (! empty($data['partner_type_enrollment_id'])) {
            $enrollment = $partner->enrollments()->find($data['partner_type_enrollment_id']);
            abort_unless($enrollment, 422, 'Enrollment does not belong to this partner.');
        }

        $link = AffiliateLink::create([
            // A link belongs to the business of its product/service (e.g. Lizyweb services).
            'business_id' => $product->business_id ?? $partner->business_id,
            'partner_id' => $partner->id,
            'partner_type_enrollment_id' => $data['partner_type_enrollment_id']
                ?? $partner->enrollments()->whereHas('partnerType', fn ($t) => $t->where('code', 'affiliate'))->value('id')
                ?? $partner->primaryEnrollment?->id,
            'code' => $data['code'],
            'product_id' => $product->id,
            'commission_percent' => $data['commission_percent'] ?? null,
            // Always system-built — never taken from the client.
            'target_url' => $this->buildTargetUrl($data['code'], $product),
            'status' => 'active',
            // Counts admin enters by hand (the tracking script can also add to them later).
            'clicks_count' => $user->isPartnerUser() ? 0 : (int) ($data['clicks_count'] ?? 0),
            'enquiries_count' => $user->isPartnerUser() ? 0 : (int) ($data['enquiries_count'] ?? 0),
            'conversions_count' => $user->isPartnerUser() ? 0 : (int) ($data['conversions_count'] ?? 0),
            'notes' => $data['notes'] ?? null,
            'created_by' => $user->id,
        ]);

        return response()->json($link->load('partner', 'partnerTypeEnrollment.partnerType', 'product'), 201);
    }

    public function show(Request $request, AffiliateLink $affiliateLink)
    {
        $user = $request->user();
        abort_if($user->isPartnerUser() && $affiliateLink->partner_id !== $user->partner_id, 403, 'You can only view your own links.');

        return $affiliateLink->load('partner', 'partnerTypeEnrollment.partnerType', 'product', 'sales');
    }

    public function update(Request $request, AffiliateLink $affiliateLink)
    {
        $user = $request->user();
        abort_if($user->isPartnerUser() && $affiliateLink->partner_id !== $user->partner_id, 403, 'You can only edit your own links.');

        // Partner, product, code and URL are fixed once created (the URL is tied to the code).
        // Only these stay editable; clicks/conversions can be corrected by admin staff only.
        $data = $request->validate([
            'commission_percent' => 'nullable|numeric|min:0|max:100',
            'status' => 'in:active,inactive',
            'notes' => 'nullable|string',
        ] + ($user->isPartnerUser() ? [] : self::COUNT_RULES));

        $affiliateLink->update($data);

        return $affiliateLink->fresh(['partner', 'partnerTypeEnrollment.partnerType', 'product']);
    }

    /** Hand-entered counts (create/edit): whole numbers, never below 0. */
    protected const COUNT_RULES = [
        'clicks_count' => 'nullable|integer|min:0',
        'enquiries_count' => 'nullable|integer|min:0',
        'conversions_count' => 'nullable|integer|min:0',
    ];

    /** Hand correction of the automatic counts: +1 / -1 on clicks, enquiries or conversions (admin only). */
    public function adjustCount(Request $request, AffiliateLink $affiliateLink)
    {
        abort_if($request->user()->isPartnerUser(), 403, 'Only admin staff can change affiliate link counts.');

        $data = $request->validate([
            'field' => 'required|in:clicks,enquiries,conversions',
            'change' => 'required|integer|in:1,-1',
        ]);

        $column = $data['field'].'_count';
        abort_if($data['change'] < 0 && $affiliateLink->{$column} <= 0, 422, 'The count is already 0.');

        $affiliateLink->increment($column, $data['change']);

        return $affiliateLink->fresh(['partner', 'partnerTypeEnrollment.partnerType', 'product']);
    }

    public function destroy(Request $request, AffiliateLink $affiliateLink)
    {
        abort_if($request->user()->isPartnerUser(), 403, 'Only admin staff can delete affiliate links.');

        $affiliateLink->delete();

        return response()->json(null, 204);
    }

    /**
     * Looked up by whoever is about to record a Sale through this link's code. Not a click —
     * real visitor clicks are counted by trackClick().
     */
    public function resolve(string $code)
    {
        $link = AffiliateLink::with('product')->where('code', $code)->where('status', 'active')->firstOrFail();

        return $link->only(['id', 'code', 'partner_id', 'partner_type_enrollment_id', 'product_id', 'target_url']);
    }

    /** Loads the partner (pinned to self for portal users) + product, and checks they're the same business. */
    protected function partnerAndProduct(Request $request, array $data): array
    {
        $user = $request->user();
        $partner = Partner::findOrFail($user->isPartnerUser() ? $user->partner_id : $data['partner_id']);
        $product = Product::findOrFail($data['product_id']);

        abort_if(
            $product->business_id !== null && (int) $product->business_id !== (int) $partner->business_id,
            422,
            'This product belongs to a different business than the partner.'
        );

        return [$partner, $product];
    }

    /** e.g. "PRT-0007-ALMONDS-K3F9" — partner code + product, with a random tail for uniqueness. */
    protected function generateUniqueCode(Partner $partner, Product $product): string
    {
        $partnerPart = Str::upper(Str::slug($partner->partner_code ?: 'P'.$partner->id));
        $productPart = Str::upper(Str::limit(Str::slug($product->name, ''), 10, '')) ?: 'P'.$product->id;

        do {
            $code = "{$partnerPart}-{$productPart}-".Str::upper(Str::random(4));
        } while (AffiliateLink::where('code', $code)->exists());

        return $code;
    }

    /** The product/service's public page + ref=CODE — see AffiliateUrlBuilder. */
    protected function buildTargetUrl(string $code, Product $product): string
    {
        $url = app(AffiliateUrlBuilder::class)->build($code, $product);
        abort_unless($url, 422, $product->isService()
            ? "\"{$product->name}\" has no Slug yet — open it under Products / Services and set its Slug first."
            : "\"{$product->name}\" has no Lizy Mart Page yet — open it under Products / Services and enter its Lizy Mart Page URL first.");

        return $url;
    }

    /**
     * Public, no login: the shop calls this when a visitor lands with ?ref=CODE, to count the
     * click. Returns only what the shop needs to remember the referral until checkout.
     */
    public function trackClick(Request $request, string $code, AffiliateTrackingService $tracking)
    {
        $link = $tracking->findActiveLink($code);
        abort_unless($link, 404, 'Unknown or inactive affiliate code.');

        $visitorId = $request->input('visitor_id');
        $counted = $tracking->recordClick(
            $link,
            is_string($visitorId) ? mb_substr($visitorId, 0, 100) : null,
            $request->ip(),
            $request->userAgent(),
        );

        return [
            'ref' => $link->code,
            'counted' => $counted,
            'product_url' => $link->product ? app(AffiliateUrlBuilder::class)->productUrl($link->product) : null,
        ];
    }

    /**
     * Applicable commission %: a product-specific commission rule for this partner (or their
     * category) wins, then the product's own standard %, then the partner's general
     * percentage rule. Returns [rate|null, human-readable source|null].
     */
    protected function resolveCommissionPercent(CommissionCalculationService $calculator, Partner $partner, Product $product): array
    {
        $rule = $calculator->resolveRule($partner, null, $product->name);

        if ($rule && $rule->rule_type === 'product') {
            return [(float) $rule->value, 'Product commission rule'];
        }
        if ($product->commission_percent !== null) {
            return [(float) $product->commission_percent, 'Product standard commission'];
        }
        if ($rule && in_array($rule->rule_type, ['percentage', 'category'], true)) {
            return [(float) $rule->value, 'Partner commission rule'];
        }

        return [null, null];
    }
}
