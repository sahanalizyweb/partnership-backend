<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ScopesToRequester;
use App\Http\Controllers\Controller;
use App\Models\AffiliateEnquiry;
use App\Models\AffiliateLink;
use App\Models\BusinessSource;
use App\Models\Partner;
use App\Models\Product;
use App\Models\Sale;
use App\Services\CommissionCalculationService;
use App\Services\ReferenceNumberService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Product sales — the primary commission-earning activity in this app. Recording a sale
 * immediately generates its Commission (see CommissionCalculationService::generateForSale),
 * which then goes through the existing commission approve/reject + ledger-posting flow.
 */
class SaleController extends Controller
{
    use ScopesToRequester;

    /** Customer + payment fields on a product sale (Sales form). */
    protected const CUSTOMER_PAYMENT_RULES = [
        'customer_name' => 'nullable|string|max:255',
        'customer_phone' => 'nullable|string|max:20',
        'payment_status' => 'nullable|in:unpaid,partial,paid',
        'amount_paid' => 'nullable|numeric|min:0',
        'payment_method' => 'nullable|string|max:100',
    ];

    public function __construct(protected ReferenceNumberService $referenceNumbers) {}

    /**
     * Amount Paid is the source of truth and Payment Status follows it; "Paid" without an
     * amount means the full total, "Unpaid" means nothing yet. Returns the columns to save.
     */
    protected function resolvePayment(array $data, float $total, ?float $currentPaid): array
    {
        $status = $data['payment_status'] ?? null;
        $paid = array_key_exists('amount_paid', $data) && $data['amount_paid'] !== null
            ? (float) $data['amount_paid']
            : match ($status) {
                'paid' => $total,
                'unpaid' => 0.0,
                'partial' => abort(422, 'Enter the Amount Paid for a partially paid sale.'),
                default => $currentPaid ?? 0.0,
            };

        abort_if($paid > $total + 0.005, 422, 'Amount Paid can\'t be more than the Total Sale Amount (₹'.number_format($total, 2).').');

        $derived = Sale::paymentStatusFor($paid, $total);
        abort_if(
            $status !== null && $status !== $derived,
            422,
            "Payment Status doesn't match the Amount Paid — ₹".number_format($paid, 2).' of ₹'.number_format($total, 2).' is "'.$derived.'".'
        );

        return ['amount_paid' => round($paid, 2), 'payment_status' => $derived];
    }

    public function index(Request $request)
    {
        $query = Sale::with([
            'partner', 'product', 'commission',
            // A sale admin recorded from a referral, and a referral's recorded sale.
            'referral:id,reference_number,customer_name,partner_id',
            'linkedSale:id,referral_id,reference_number,product_name,quantity,sale_amount,sale_date,status,payment_status,amount_paid',
            'linkedSale.commission',
        ]);
        $query = $this->scopeBusiness($query, $request);
        $query = $this->scopePartner($query, $request);

        if ($request->filled('partner_id')) {
            $query->where('partner_id', $request->integer('partner_id'));
        }
        // 'sale' = actual sales only, 'referral' = referrals only (they're separate records).
        if ($request->filled('sale_type')) {
            $query->where('sale_type', $request->input('sale_type'));
        }
        // Referrals admin can still record a Sale for: pending, with no sale or commission yet.
        if ($request->boolean('linkable')) {
            $query->where('sale_type', 'referral')->where('status', 'pending')
                ->doesntHave('linkedSale')->doesntHave('commission');
        }
        if ($request->filled('partner_type_code')) {
            // The role the sale was recorded under; legacy rows (no enrollment) fall back to the partner's type.
            $code = $request->string('partner_type_code');
            $query->where(fn ($q) => $q
                ->whereHas('partnerTypeEnrollment.partnerType', fn ($t) => $t->where('code', $code))
                ->orWhere(fn ($legacy) => $legacy->whereNull('partner_type_enrollment_id')
                    ->whereHas('partner.partnerType', fn ($t) => $t->where('code', $code))));
        }

        return $query->orderByDesc('id')->paginate($request->integer('per_page', 20));
    }

    public function store(Request $request, CommissionCalculationService $calculator)
    {
        if ($request->input('sale_type') === 'referral') {
            return $this->storeReferral($request);
        }

        // A partner who is only a Referral Partner reports referrals, never product sales.
        $user = $request->user();
        if ($user->isPartnerUser()) {
            $roles = Partner::find($user->partner_id)?->enrollments()
                ->whereNotIn('status', ['suspended', 'rejected', 'inactive'])
                ->with('partnerType:id,code')->get()->pluck('partnerType.code') ?? collect();
            abort_if(
                $roles->contains('referral') && ! $roles->intersect(['sales', 'affiliate'])->count(),
                422,
                'Referral Partners record referrals (customer details only) — Lizy records the actual sale.'
            );
        }

        $data = $request->validate([
            'partner_id' => 'nullable|exists:partners,id',
            'partner_type_enrollment_id' => 'nullable|exists:partner_type_enrollments,id',
            'business_source_id' => 'nullable|exists:business_sources,id',
            'affiliate_link_code' => 'nullable|string|exists:affiliate_links,code',
            // Admin records sales against the Products/Services catalog only — no free-text product.
            'product_id' => ($user->isPartnerUser() ? 'nullable' : 'required').'|exists:products,id',
            'product_name' => 'required_without:product_id|string|max:255',
            // Optional with a catalog product (blank = the product's price), required without one.
            'unit_price' => 'nullable|required_without:product_id|numeric|min:0',
            'quantity' => 'nullable|integer|min:1',
            // The actual amount the customer is charged; defaults to product price × quantity.
            'sale_amount' => 'nullable|numeric|min:0',
            'referral_id' => 'nullable|integer|exists:sales,id',
            // Admin converting an affiliate enquiry (Affiliate Enquiries → Convert to Sale).
            'affiliate_enquiry_id' => 'nullable|integer|exists:affiliate_enquiries,id',
            'sale_date' => 'required|date',
            'reference_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'commission_rate' => 'nullable|numeric|min:0|max:100',
        ] + self::CUSTOMER_PAYMENT_RULES, ['product_id.required' => 'Select the Product/Service from the catalog.']);

        // A partner-portal user can only ever record a sale for themselves, never link a referral.
        if ($user->isPartnerUser()) {
            $data['partner_id'] = $user->partner_id;
            unset($data['referral_id'], $data['sale_amount'], $data['affiliate_enquiry_id']);
        }

        // Admin recording the actual Sale for a partner's Referral: the sale belongs to that
        // referral's partner (under their Referral role) and the customer carries over.
        $referral = null;
        if (! empty($data['referral_id'])) {
            $referral = Sale::where('sale_type', 'referral')->find($data['referral_id']);
            abort_unless($referral, 422, 'The selected Referral Reference is not a referral.');
            abort_if($referral->status === 'cancelled', 422, 'This referral was cancelled — it can\'t be linked to a sale.');
            abort_if($referral->linkedSale()->exists(), 422, "Referral {$referral->reference_number} already has a sale recorded.");
            abort_if($referral->commission()->exists(), 422, "Referral {$referral->reference_number} was already completed with its own commission.");
            $data['partner_id'] = $referral->partner_id;
            $data['partner_type_enrollment_id'] = $referral->partner_type_enrollment_id;
            $data['customer_name'] = ($data['customer_name'] ?? null) ?: $referral->customer_name;
            $data['customer_phone'] = ($data['customer_phone'] ?? null) ?: $referral->customer_phone;
        }

        // Converting an affiliate enquiry: the sale belongs to its partner, the customer carries
        // over, and the enquiry's link (if still active) attributes it and counts the conversion.
        $enquiry = null;
        if (! empty($data['affiliate_enquiry_id'])) {
            $enquiry = AffiliateEnquiry::with('affiliateLink')->findOrFail($data['affiliate_enquiry_id']);
            abort_if($enquiry->status === 'converted' || $enquiry->sale_id, 422, 'This enquiry was already converted to a sale.');
            $data['partner_id'] = $enquiry->partner_id;
            $data['customer_name'] = ($data['customer_name'] ?? null) ?: $enquiry->customer_name;
            $data['customer_phone'] = ($data['customer_phone'] ?? null) ?: $enquiry->customer_phone;
            if (empty($data['affiliate_link_code']) && $enquiry->affiliateLink?->status === 'active') {
                $data['affiliate_link_code'] = $enquiry->affiliateLink->code;
            }
        }

        abort_unless($data['partner_id'] ?? null, 422, 'A partner is required.');

        // Double-commission guard (requirement #9): an order, identified by its reference
        // number, may only ever be attributed — and commissioned — once, whether that
        // happens via an affiliate-link click or a later manual admin attribution.
        abort_if(
            ! empty($data['reference_number']) && Sale::where('reference_number', $data['reference_number'])->exists(),
            422,
            'This order has already been attributed to a sale — it cannot be attributed twice.'
        );

        $partner = Partner::findOrFail($data['partner_id']);
        $quantity = $data['quantity'] ?? 1;
        // A referral's own agreed Commission % (set by admin on the referral) applies unless overridden.
        $rateOverride = $data['commission_rate'] ?? $referral?->commission_percent;

        // Selecting a catalog Product/Service pins its name and drives the fixed standard
        // commission % (see generateForSale). The Unit Price sent wins; blank = the product's
        // standard amount (the product itself is never changed).
        $product = ! empty($data['product_id']) ? Product::findOrFail($data['product_id']) : null;
        $productName = $product ? $product->name : $data['product_name'];
        $unitPrice = isset($data['unit_price']) ? (float) $data['unit_price'] : (float) $product?->amount;

        // Method A (affiliate link) vs Method B (manual) attribution (requirements #8/#9).
        $affiliateLink = ! empty($data['affiliate_link_code'])
            ? AffiliateLink::where('code', $data['affiliate_link_code'])->where('status', 'active')->first()
            : null;

        // The commission % admin verified on the affiliate link applies unless explicitly overridden.
        if ($rateOverride === null && $affiliateLink?->commission_percent !== null) {
            $rateOverride = $affiliateLink->commission_percent;
        }

        $saleAmount = round($unitPrice * $quantity, 2);
        if (isset($data['sale_amount']) && $data['sale_amount'] !== null) {
            // Admin entered the actual sale amount; the commission is calculated on it.
            $saleAmount = round((float) $data['sale_amount'], 2);
            $unitPrice = round($saleAmount / $quantity, 2);
        }

        $sale = Sale::create([
            'business_id' => $partner->business_id,
            'partner_id' => $partner->id,
            'partner_type_enrollment_id' => $data['partner_type_enrollment_id']
                ?? $affiliateLink?->partner_type_enrollment_id
                ?? $partner->primaryEnrollment?->id,
            // Affiliate sales (link or enquiry) earn under the "Affiliate" business source.
            'business_source_id' => $data['business_source_id']
                ?? ($affiliateLink || $enquiry ? BusinessSource::whereNull('business_id')->where('code', 'affiliate')->value('id') : null),
            'attribution_method' => $affiliateLink ? 'affiliate_link' : 'manual',
            'affiliate_link_id' => $affiliateLink?->id,
            'referral_id' => $referral?->id,
            'product_id' => $product?->id,
            'product_name' => $productName,
            'customer_name' => $data['customer_name'] ?? null,
            'customer_phone' => $data['customer_phone'] ?? null,
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'sale_amount' => $saleAmount,
            // The rate this sale's commission is calculated at (null = resolved from commission rules).
            'commission_percent' => $rateOverride ?? $product?->commission_percent,
            'payment_method' => $data['payment_method'] ?? null,
            'sale_date' => $data['sale_date'],
        ] + $this->resolvePayment($data, $saleAmount, 0.0) + [
            // Client may still supply their own external reference (e.g. a real Shopify order
            // number) — only auto-generate one when they didn't, so every sale ends up with a
            // unique system reference either way.
            'reference_number' => $data['reference_number'] ?? $this->referenceNumbers->next('SAL', $partner->business_id),
            'notes' => $data['notes'] ?? null,
            'created_by' => $user->id,
        ]);

        $affiliateLink?->increment('conversions_count');

        $calculator->generateForSale($sale, $rateOverride !== null ? (float) $rateOverride : null);

        // The referral is now converted — its Sale (with the commission) is what the partner sees.
        $referral?->update(['status' => 'confirmed']);
        $enquiry?->update(['status' => 'converted', 'sale_id' => $sale->id]);

        return response()->json($sale->load('partner', 'product', 'commission', 'affiliateLink', 'businessSource', 'referral'), 201);
    }

    /**
     * A referral: the partner only refers the customer — no product/qty (Lizy Mart handles the
     * order). No commission yet; it's generated from the final Sale Amount when the referral is
     * marked completed (see completeReferral).
     */
    protected function storeReferral(Request $request)
    {
        $data = $request->validate([
            'partner_id' => 'nullable|exists:partners,id',
            'partner_type_enrollment_id' => 'nullable|exists:partner_type_enrollments,id',
            'business_source_id' => 'nullable|exists:business_sources,id',
            'customer_name' => 'required|string|max:255',
            'customer_phone' => 'nullable|string|max:20',
            'sale_amount' => 'nullable|numeric|min:0',
            'commission_percent' => 'nullable|numeric|min:0|max:100',
            'sale_date' => ($request->user()->isPartnerUser() ? 'nullable' : 'required').'|date',
            'reference_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'expected_amount' => 'nullable|numeric|min:0',
        ]);

        $user = $request->user();
        if ($user->isPartnerUser()) {
            // A Referral Partner only reports the customer (+ an optional expected amount). The
            // final amount, commission % and reference number are admin's / the system's.
            $data['partner_id'] = $user->partner_id;
            $data['sale_date'] = now()->toDateString();
            unset($data['sale_amount'], $data['commission_percent'], $data['reference_number'],
                $data['partner_type_enrollment_id'], $data['business_source_id']);
        }
        abort_unless($data['partner_id'] ?? null, 422, 'A partner is required.');
        abort_if(
            ! empty($data['reference_number']) && Sale::where('reference_number', $data['reference_number'])->exists(),
            422,
            'This reference number is already used by another sale/referral.'
        );

        $partner = Partner::findOrFail($data['partner_id']);
        $amount = round((float) ($data['sale_amount'] ?? 0), 2);

        $sale = Sale::create([
            'business_id' => $partner->business_id,
            'partner_id' => $partner->id,
            'partner_type_enrollment_id' => $data['partner_type_enrollment_id']
                ?? $partner->enrollments()->whereHas('partnerType', fn ($t) => $t->where('code', 'referral'))->value('id')
                ?? $partner->primaryEnrollment?->id,
            'business_source_id' => $data['business_source_id'] ?? null,
            'attribution_method' => 'manual',
            'sale_type' => 'referral',
            'customer_name' => $data['customer_name'],
            'customer_phone' => $data['customer_phone'] ?? null,
            'unit_price' => $amount,
            'quantity' => 1,
            'sale_amount' => $amount,
            'expected_amount' => $data['expected_amount'] ?? null,
            'commission_percent' => $data['commission_percent'] ?? null,
            'sale_date' => $data['sale_date'],
            'reference_number' => $data['reference_number'] ?? $this->referenceNumbers->next('REF', $partner->business_id),
            'notes' => $data['notes'] ?? null,
            'created_by' => $user->id,
        ]);

        return response()->json($sale->load('partner', 'commission'), 201);
    }

    /** Referral marked completed: its commission is generated from the final Sale Amount. */
    protected function completeReferral(Sale $sale): void
    {
        if (! $sale->commission) {
            app(CommissionCalculationService::class)->generateForSale(
                $sale,
                $sale->commission_percent !== null ? (float) $sale->commission_percent : null
            );
        }
    }

    public function show(Request $request, Sale $sale)
    {
        $user = $request->user();
        abort_if($user->isPartnerUser() && $sale->partner_id !== $user->partner_id, 403, 'You can only view your own sales.');

        return $sale->load('partner', 'product', 'commission.commissionRule', 'createdBy');
    }

    public function update(Request $request, Sale $sale)
    {
        abort_if($request->user()->isPartnerUser(), 403, 'Only admin staff can edit sale details.');

        $data = $request->validate([
            'product_id' => 'nullable|exists:products,id',
            'product_name' => 'sometimes|required|string|max:255',
            'unit_price' => 'nullable|numeric|min:0',
            'quantity' => 'nullable|integer|min:1',
            'sale_date' => 'sometimes|required|date',
            'status' => 'sometimes|required|in:pending,confirmed,cancelled',
            'reference_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'sale_amount' => 'nullable|numeric|min:0',
            'expected_amount' => 'nullable|numeric|min:0',
            'commission_percent' => 'nullable|numeric|min:0|max:100',
            'commission_rate' => 'nullable|numeric|min:0|max:100',
        ] + self::CUSTOMER_PAYMENT_RULES);

        abort_if(
            $sale->isReferral() && array_key_exists('customer_name', $data) && blank($data['customer_name']),
            422,
            'Customer Name is required for a referral.'
        );

        $paymentFields = array_intersect_key($data, array_flip(['payment_status', 'amount_paid']));
        if ($sale->isReferral()) {
            // Referrals keep their own completion flow; payment tracking is for product sales.
            unset($data['payment_status'], $data['amount_paid'], $data['payment_method'], $data['commission_rate']);
            $paymentFields = [];
        } else {
            // Product sales: sale_amount (if sent) is the actual amount charged, otherwise price × qty;
            // the rate comes in as commission_rate.
            unset($data['commission_percent'], $data['payment_status'], $data['amount_paid']);
            if (array_key_exists('commission_rate', $data)) {
                $data['commission_percent'] = $data['commission_rate'];
            }
            unset($data['commission_rate']);
        }

        // A referral is completed only by admin recording its actual Sale (Sales → Record Sale →
        // Referral Reference), which carries the commission — never directly, so a referral can
        // never earn a second commission of its own.
        abort_if(
            $sale->isReferral() && ($data['status'] ?? null) === 'confirmed' && $sale->status !== 'confirmed',
            422,
            'Record the Sale for this referral under Sales (select its Referral Reference) — that completes it and calculates the commission.'
        );
        $completing = false;

        DB::transaction(function () use ($sale, $data, $paymentFields) {
            $this->applySaleUpdate($sale, $data);

            // Re-check what the customer paid against the (possibly changed) total. Rolls the
            // whole edit back if it no longer fits, e.g. quantity lowered below the amount paid.
            if (! $sale->isReferral() && ($paymentFields || $sale->payment_status !== null)) {
                $sale->refresh();
                $sale->update($this->resolvePayment($paymentFields, (float) $sale->sale_amount, $sale->amount_paid !== null ? (float) $sale->amount_paid : null));
            }
        });

        if ($completing) {
            $this->completeReferral($sale->fresh());
        }

        return $sale->fresh(['partner', 'product', 'commission']);
    }

    public function destroy(Request $request, Sale $sale)
    {
        abort_if($request->user()->isPartnerUser(), 403, 'Only admin staff can delete sales.');

        $this->performDelete($sale);

        return response()->json(null, 204);
    }

    /**
     * Partner Portal self-service edit of a Sale/Referral they recorded — mirrors the
     * me/partner self-service pattern (ownership enforced here, not via permission
     * middleware). Only allowed while the sale is still 'pending' and its commission (if
     * generated) hasn't been actioned yet, so a partner can never alter a confirmed
     * sale/referral or a commission an admin has already approved/rejected/paid.
     */
    public function updateMine(Request $request, Sale $sale)
    {
        $user = $request->user();
        abort_unless($user->isPartnerUser(), 403, 'This endpoint is for partner-portal users only.');
        abort_if($sale->partner_id !== $user->partner_id, 403, 'You can only edit your own sales.');
        abort_unless($this->isEditableByPartner($sale), 422, 'This sale/referral can no longer be edited — it has already been confirmed, or its commission has already been actioned.');

        if ($sale->isReferral()) {
            // A referral partner can only correct the customer details they reported.
            $data = $request->validate([
                'customer_name' => 'sometimes|required|string|max:255',
                'customer_phone' => 'nullable|string|max:20',
                'notes' => 'nullable|string',
                'expected_amount' => 'nullable|numeric|min:0',
            ]);
            $sale->update($data);

            return $sale->fresh(['partner', 'product', 'commission']);
        }

        $data = $request->validate([
            'product_id' => 'nullable|exists:products,id',
            'product_name' => 'sometimes|required|string|max:255',
            'unit_price' => 'nullable|numeric|min:0',
            'quantity' => 'nullable|integer|min:1',
            'sale_date' => 'sometimes|required|date',
            'reference_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $this->applySaleUpdate($sale, $data);

        return $sale->fresh(['partner', 'product', 'commission']);
    }

    public function destroyMine(Request $request, Sale $sale)
    {
        $user = $request->user();
        abort_unless($user->isPartnerUser(), 403, 'This endpoint is for partner-portal users only.');
        abort_if($sale->partner_id !== $user->partner_id, 403, 'You can only delete your own sales.');
        abort_unless($this->isEditableByPartner($sale), 422, 'This sale/referral can no longer be deleted — it has already been confirmed, or its commission has already been actioned.');

        $this->performDelete($sale);

        return response()->json(null, 204);
    }

    protected function isEditableByPartner(Sale $sale): bool
    {
        if ($sale->status !== 'pending') {
            return false;
        }

        $commission = $sale->commission;

        return ! $commission || $commission->status === 'pending';
    }

    /** Applies field updates + recomputes sale_amount, then keeps the linked Commission in step. */
    protected function applySaleUpdate(Sale $sale, array $data): void
    {
        if ($sale->isReferral()) {
            // No product/qty on a referral: the Sale Amount is entered directly.
            unset($data['product_id'], $data['product_name'], $data['unit_price'], $data['quantity']);
            if (array_key_exists('sale_amount', $data)) {
                $data['sale_amount'] = round((float) ($data['sale_amount'] ?? 0), 2);
                $data['unit_price'] = $data['sale_amount'];
            }
            $sale->update($data);
            $this->syncCommissionAmount($sale);

            return;
        }

        // A blank Unit Price means "use the product's price" (or keep the current one).
        if (array_key_exists('unit_price', $data) && $data['unit_price'] === null) {
            unset($data['unit_price']);
        }

        if (array_key_exists('product_id', $data)) {
            $product = $data['product_id'] ? Product::findOrFail($data['product_id']) : null;
            $data['product_id'] = $product?->id;
            if ($product) {
                $data['product_name'] = $product->name;
                $data['unit_price'] ??= (float) $product->amount;
            }
        }

        $quantity = $data['quantity'] ?? $sale->quantity;
        if (isset($data['sale_amount']) && $data['sale_amount'] !== null) {
            // An entered actual Sale Amount wins over price × quantity.
            $data['sale_amount'] = round((float) $data['sale_amount'], 2);
            $data['unit_price'] = round($data['sale_amount'] / max((int) $quantity, 1), 2);
        } else {
            unset($data['sale_amount']);
            $unitPrice = $data['unit_price'] ?? $sale->unit_price;
            $data['sale_amount'] = round($unitPrice * $quantity, 2);
        }

        $sale->update($data);

        $this->syncCommissionAmount($sale);
    }

    /**
     * Keeps the linked Commission's amount in step with the Sale's base amount whenever the
     * sale is edited — preserving whatever rate produced it (the product's fixed standard %,
     * or a manual override) rather than re-resolving rules. No-ops once the commission has
     * been actioned (not 'pending'), so an edit can never silently change an approved/paid
     * commission.
     */
    protected function syncCommissionAmount(Sale $sale): void
    {
        $commission = $sale->commission;
        if (! $commission || $commission->status !== 'pending') {
            return;
        }

        $oldBase = (float) $commission->base_amount;
        // A referral carries its own Commission %; otherwise keep the rate that produced it.
        // The sale's own Commission % when it has one (referrals, and sales from the Sales form);
        // otherwise keep the rate that produced the commission.
        $rate = $sale->commission_percent !== null
            ? (float) $sale->commission_percent
            : ($oldBase > 0 ? ((float) $commission->commission_amount / $oldBase) * 100 : 0.0);
        $newBase = (float) $sale->sale_amount;

        $commission->update([
            'base_amount' => $newBase,
            'commission_amount' => round($newBase * $rate / 100, 2),
        ]);
    }

    /**
     * A commission that was never actioned (still 'pending') carries no financial history
     * worth keeping once its sale is gone, so it's deleted along with the sale; an
     * approved/payable/paid/rejected one is kept for audit history (sale_id just goes null).
     */
    protected function performDelete(Sale $sale): void
    {
        abort_if(
            $sale->isReferral() && $sale->linkedSale()->exists(),
            422,
            'This referral has a recorded sale — delete that sale first.'
        );

        if ($sale->commission && $sale->commission->status === 'pending') {
            $sale->commission->delete();
        }

        // Removing the sale recorded for a referral puts the referral back to pending, and an
        // enquiry converted into it back to contacted.
        $sale->referral?->update(['status' => 'pending']);
        AffiliateEnquiry::where('sale_id', $sale->id)->update(['status' => 'contacted', 'sale_id' => null]);

        $sale->delete();
    }
}
