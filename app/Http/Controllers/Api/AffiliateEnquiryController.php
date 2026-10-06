<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ScopesToRequester;
use App\Http\Controllers\Controller;
use App\Models\AffiliateEnquiry;
use App\Models\AffiliateLink;
use App\Models\Partner;
use App\Models\Product;
use App\Services\AffiliateTrackingService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Affiliate enquiries: the lizyweb.in website forwards each enquiry form sent through a
 * partner's ?ref= link (public, key-protected), and admin or the affiliate partner can also
 * enter one by hand. Admin converts an enquiry into a Sale from Record Sale.
 */
class AffiliateEnquiryController extends Controller
{
    use ScopesToRequester;

    /** The same customer (phone) for the same link/service within this window is one enquiry. */
    protected const DUPLICATE_HOURS = 24;

    protected const RELATIONS = [
        'partner:id,name,partner_code', 'product:id,name,type,slug', 'affiliateLink:id,code,status,commission_percent',
        'sale:id,reference_number', 'business:id,name,code',
    ];

    // ------------------------------------------------------------------ Public (website backend)

    /** POST public/affiliate-enquiries — called server-to-server by the website with X-Website-Key. */
    public function storeFromWebsite(Request $request, AffiliateTrackingService $tracking)
    {
        $key = (string) config('services.website.api_key');
        abort_if($key === '', 503, 'Website enquiries are not set up yet (WEBSITE_API_KEY is empty).');
        abort_unless(hash_equals($key, (string) $request->header('X-Website-Key')), 401, 'Invalid website key.');

        $data = $request->validate([
            'ref' => 'required|string|max:100',
            'name' => 'required|string|max:255',
            'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{6,20}$/'],
            'email' => 'nullable|email|max:255',
            'message' => 'nullable|string|max:5000',
            'service_slug' => 'nullable|string|max:255',
            'website_enquiry_id' => 'nullable|string|max:100',
            'page_url' => 'nullable|string|max:2048',
        ]);

        // The website re-sent an enquiry it already delivered: return the stored one.
        $websiteId = $data['website_enquiry_id'] ?? null;
        if ($websiteId && $existing = AffiliateEnquiry::where('website_enquiry_id', $websiteId)->first()) {
            return $this->publicResponse($existing, 200);
        }

        $link = $tracking->findActiveLink($data['ref']);
        if (! $link) {
            throw ValidationException::withMessages(['ref' => 'Unknown or inactive affiliate code.']);
        }

        $phone = AffiliateEnquiry::normalizePhone($data['phone']);
        if ($existing = $this->recentDuplicate($phone, 'affiliate_link_id', $link->id)) {
            return $this->publicResponse($existing, 200);
        }

        $product = $link->product ?? (filled($data['service_slug'] ?? null)
            ? Product::where('business_id', $link->business_id)->where('slug', $data['service_slug'])->first()
            : null);

        try {
            $enquiry = DB::transaction(function () use ($link, $product, $data, $phone, $websiteId) {
                $enquiry = AffiliateEnquiry::create([
                    'business_id' => $link->business_id,
                    'affiliate_link_id' => $link->id,
                    'partner_id' => $link->partner_id,
                    'product_id' => $product?->id,
                    'service_slug' => $data['service_slug'] ?? $product?->slug,
                    'customer_name' => $data['name'],
                    'customer_phone' => $phone,
                    'customer_email' => $data['email'] ?? null,
                    'message' => $data['message'] ?? null,
                    'source_page_url' => $data['page_url'] ?? null,
                    'website_enquiry_id' => $websiteId,
                    'source' => 'link',
                    'status' => 'new',
                    'enquiry_date' => now()->toDateString(),
                ]);
                $link->increment('enquiries_count');

                return $enquiry;
            });
        } catch (QueryException $e) {
            // Two copies of the same website enquiry raced: the other one was stored.
            $existing = $websiteId ? AffiliateEnquiry::where('website_enquiry_id', $websiteId)->first() : null;
            throw_unless($existing, $e);

            return $this->publicResponse($existing, 200);
        }

        return $this->publicResponse($enquiry, 201);
    }

    protected function publicResponse(AffiliateEnquiry $enquiry, int $status)
    {
        return response()->json(['id' => $enquiry->id, 'status' => $enquiry->status, 'duplicate' => $status === 200], $status);
    }

    protected function recentDuplicate(string $phone, string $column, int $value): ?AffiliateEnquiry
    {
        return AffiliateEnquiry::where($column, $value)->where('customer_phone', $phone)
            ->where('created_at', '>=', now()->subHours(self::DUPLICATE_HOURS))->first();
    }

    // ------------------------------------------------------------------ Admin + partner portal

    public function index(Request $request)
    {
        $query = AffiliateEnquiry::with(self::RELATIONS);
        $query = $this->scopeBusiness($query, $request);
        $query = $this->scopePartner($query, $request);

        foreach (['status', 'partner_id', 'source', 'business_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        $page = $query->orderByDesc('enquiry_date')->orderByDesc('id')->paginate($request->integer('per_page', 20));

        if ($request->user()->isPartnerUser()) {
            $page->getCollection()->transform(fn (AffiliateEnquiry $e) => $this->forPartner($e));
        }

        return $page;
    }

    public function show(Request $request, AffiliateEnquiry $affiliateEnquiry)
    {
        $affiliateEnquiry->load(self::RELATIONS);
        $user = $request->user();

        if ($user->isPartnerUser()) {
            abort_if($affiliateEnquiry->partner_id !== $user->partner_id, 403, 'You can only view your own enquiries.');

            // Full details only for the partner's own hand-entered enquiry they may still edit.
            return $affiliateEnquiry->isEditableByPartner() ? $affiliateEnquiry : $this->forPartner($affiliateEnquiry);
        }

        return $affiliateEnquiry;
    }

    /** Hand entry — admin for any affiliate partner, or an affiliate partner for themselves. */
    public function store(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'partner_id' => $user->isPartnerUser() ? 'nullable' : 'required|exists:partners,id',
        ] + $this->entryRules($user->isPartnerUser()));

        $partner = Partner::findOrFail($user->isPartnerUser() ? $user->partner_id : $data['partner_id']);
        abort_unless($this->isAffiliatePartner($partner), 422, 'Enquiries are recorded for Affiliate Partners only.');

        $product = $this->productFor($request, $data['product_id']);
        $phone = AffiliateEnquiry::normalizePhone($data['customer_phone']);

        $duplicate = $this->recentDuplicate($phone, 'product_id', $product->id);
        abort_if($duplicate, 422, 'This customer already has an enquiry for this service in the last 24 hours.');

        $link = AffiliateLink::where('partner_id', $partner->id)->where('product_id', $product->id)->where('status', 'active')->first();

        $enquiry = DB::transaction(function () use ($data, $partner, $product, $phone, $link) {
            $enquiry = AffiliateEnquiry::create([
                'business_id' => $product->business_id,
                'affiliate_link_id' => $link?->id,
                'partner_id' => $partner->id,
                'product_id' => $product->id,
                'service_slug' => $product->slug,
                'customer_name' => $data['customer_name'],
                'customer_phone' => $phone,
                'customer_email' => $data['customer_email'] ?? null,
                'message' => $data['message'] ?? null,
                'source' => 'manual',
                'status' => $data['status'] ?? 'new',
                'enquiry_date' => $data['enquiry_date'] ?? now()->toDateString(),
                'notes' => $data['notes'] ?? null,
            ]);
            $link?->increment('enquiries_count');

            return $enquiry;
        });

        return response()->json($enquiry->load(self::RELATIONS), 201);
    }

    /** Admin: edit any enquiry and change its status. */
    public function update(Request $request, AffiliateEnquiry $affiliateEnquiry)
    {
        abort_if($request->user()->isPartnerUser(), 403, 'Only admin staff can edit this enquiry.');

        $data = $request->validate(array_merge($this->sometimes($this->entryRules(false)), [
            'status' => 'sometimes|required|in:'.implode(',', AffiliateEnquiry::STATUSES),
        ]));

        $this->applyUpdate($request, $affiliateEnquiry, $data);

        return $affiliateEnquiry->fresh(self::RELATIONS);
    }

    public function destroy(Request $request, AffiliateEnquiry $affiliateEnquiry)
    {
        abort_if($request->user()->isPartnerUser(), 403, 'Only admin staff can delete this enquiry.');
        $this->deleteEnquiry($affiliateEnquiry);

        return response()->json(null, 204);
    }

    /** Partner portal: edit their own hand-entered enquiry while it's still "new". */
    public function updateMine(Request $request, AffiliateEnquiry $affiliateEnquiry)
    {
        $this->assertPartnerCanChange($request, $affiliateEnquiry);

        $data = $request->validate($this->sometimes($this->entryRules(true)));
        $this->applyUpdate($request, $affiliateEnquiry, $data);

        return $affiliateEnquiry->fresh(self::RELATIONS);
    }

    public function destroyMine(Request $request, AffiliateEnquiry $affiliateEnquiry)
    {
        $this->assertPartnerCanChange($request, $affiliateEnquiry);
        $this->deleteEnquiry($affiliateEnquiry);

        return response()->json(null, 204);
    }

    // ------------------------------------------------------------------ Helpers

    protected function entryRules(bool $partnerUser): array
    {
        return [
            'product_id' => 'required|exists:products,id',
            'customer_name' => 'required|string|max:255',
            'customer_phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{6,20}$/'],
            'customer_email' => 'nullable|email|max:255',
            'message' => 'nullable|string|max:5000',
            'enquiry_date' => 'nullable|date',
        ] + ($partnerUser ? [] : ['status' => 'nullable|in:new,contacted,lost', 'notes' => 'nullable|string']);
    }

    /** Edit forms send only the fields they change. */
    protected function sometimes(array $rules): array
    {
        return array_map(fn ($r) => is_array($r) ? ['sometimes', ...$r] : "sometimes|$r", $rules);
    }

    protected function applyUpdate(Request $request, AffiliateEnquiry $enquiry, array $data): void
    {
        if (isset($data['customer_phone'])) {
            $data['customer_phone'] = AffiliateEnquiry::normalizePhone($data['customer_phone']);
        }
        if (isset($data['product_id']) && (int) $data['product_id'] !== (int) $enquiry->product_id) {
            $product = $this->productFor($request, $data['product_id']);
            $data['service_slug'] = $product->slug;
        }

        $enquiry->update($data);
    }

    /** Removing an enquiry also takes it off its link's enquiry count. */
    protected function deleteEnquiry(AffiliateEnquiry $enquiry): void
    {
        DB::transaction(function () use ($enquiry) {
            $link = $enquiry->affiliateLink;
            if ($link && $link->enquiries_count > 0) {
                $link->decrement('enquiries_count');
            }
            $enquiry->delete();
        });
    }

    protected function assertPartnerCanChange(Request $request, AffiliateEnquiry $enquiry): void
    {
        $user = $request->user();
        abort_unless($user->isPartnerUser(), 403, 'This endpoint is for partner-portal users only.');
        abort_if($enquiry->partner_id !== $user->partner_id, 403, 'You can only change your own enquiries.');
        abort_unless($enquiry->isEditableByPartner(), 422, 'This enquiry is locked — only your own hand-entered enquiries can be changed, and only while they are "new".');
    }

    /** The product/service, which must be in the requester's business (scoped staff and partners). */
    protected function productFor(Request $request, int|string $productId): Product
    {
        $product = Product::findOrFail($productId);
        $businessId = $request->user()->business_id;
        abort_if($businessId && (int) $product->business_id !== (int) $businessId, 422, 'This product/service belongs to another business.');

        return $product;
    }

    protected function isAffiliatePartner(Partner $partner): bool
    {
        return $partner->partnerType?->code === 'affiliate'
            || $partner->enrollments()->whereNotIn('status', ['suspended', 'rejected', 'inactive'])
                ->whereHas('partnerType', fn ($t) => $t->where('code', 'affiliate'))->exists();
    }

    /**
     * What a partner sees of an enquiry: the customer's first name and only the last 2 digits of
     * the phone — the customer gave their details to Lizy, not to the partner.
     */
    protected function forPartner(AffiliateEnquiry $e): array
    {
        $phone = (string) $e->customer_phone;

        return [
            'id' => $e->id,
            'enquiry_date' => $e->enquiry_date?->toDateString(),
            'product' => $e->product?->only(['id', 'name', 'type']),
            'service_slug' => $e->service_slug,
            'customer_name' => strtok(trim($e->customer_name), ' ') ?: '',
            'customer_phone' => str_repeat('•', max(strlen($phone) - 2, 0)).substr($phone, -2),
            'source' => $e->source,
            'status' => $e->status,
            'can_edit' => $e->isEditableByPartner(),
        ];
    }
}
