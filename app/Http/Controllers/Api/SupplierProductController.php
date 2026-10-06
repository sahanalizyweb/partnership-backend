<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ScopesToRequester;
use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\SupplierProduct;
use Illuminate\Http\Request;

/**
 * Catalog of products/materials Supplier Partners supply to us. Every entry admin adds belongs
 * to one Supplier Partner (the same product from two suppliers = two entries, each with its own
 * price/details). A Supplier Partner sees and manages only their own entries from the portal —
 * never another supplier's. (Older entries saved with no supplier, partner_id null, stay
 * visible to admin only until a supplier is set on them.)
 */
class SupplierProductController extends Controller
{
    use ScopesToRequester;

    public function index(Request $request)
    {
        $query = SupplierProduct::with(self::PARTNER_WITH_CATEGORY);
        $query = $this->scopeBusiness($query, $request);

        $user = $request->user();
        if ($user->isPartnerUser()) {
            // Only this supplier's own products.
            $query->where('partner_id', $user->partner_id);
        } elseif ($request->filled('partner_id')) {
            // Admin picking a product for one supplier (e.g. Log Supply): that supplier's own
            // entries + the general catalog, never another supplier's.
            $partnerId = $request->integer('partner_id');
            $query->where(fn ($q) => $q->whereNull('partner_id')->orWhere('partner_id', $partnerId));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return $query->orderBy('name')->get()->each(fn ($p) => $this->withSupplierCategory($p));
    }

    /** Supplier + what's needed to name their category (Manufacturer / Distributor / …). */
    protected const PARTNER_WITH_CATEGORY = [
        'partner:id,name,partner_code,partner_category_id',
        'partner.partnerCategory:id,name',
        'partner.enrollments:id,partner_id,partner_type_id,partner_category_id',
        'partner.enrollments.partnerType:id,code',
        'partner.enrollments.partnerCategory:id,name',
    ];

    /**
     * Adds `supplier_category`: the category chosen on the partner's Supplier role, falling
     * back to the partner's own category. Display only — nothing is stored.
     */
    protected function withSupplierCategory(SupplierProduct $product): SupplierProduct
    {
        $partner = $product->partner;
        $enrollment = $partner?->enrollments->first(fn ($e) => $e->partnerType?->code === 'supplier');

        $product->setAttribute('supplier_category', $enrollment?->partnerCategory?->name ?? $partner?->partnerCategory?->name);
        // Keep the payload lean: the category name is all the screens need.
        $partner?->unsetRelation('enrollments')->unsetRelation('partnerCategory');

        return $product;
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            // Admin must pick the Supplier Partner; a supplier in the portal is always themselves.
            'partner_id' => $user->isPartnerUser() ? 'nullable' : 'required|exists:partners,id',
            'name' => 'required|string|max:255',
            'details' => 'nullable|string',
            'standard_price' => 'nullable|numeric|min:0',
            'status' => 'in:active,inactive',
        ], ['partner_id.required' => 'Select the Supplier Partner for this product.']);

        $partner = $this->supplierPartner($user->isPartnerUser() ? $user->partner_id : $data['partner_id']);
        $data['partner_id'] = $partner->id;
        $data['business_id'] = $partner->business_id;

        $product = SupplierProduct::create($data);

        return response()->json($this->withSupplierCategory($product->load(self::PARTNER_WITH_CATEGORY)), 201);
    }

    public function show(Request $request, SupplierProduct $supplierProduct)
    {
        $this->authorizeOwnership($request, $supplierProduct);

        return $supplierProduct;
    }

    public function update(Request $request, SupplierProduct $supplierProduct)
    {
        $this->authorizeOwnership($request, $supplierProduct);

        $data = $request->validate([
            'partner_id' => 'sometimes|required|exists:partners,id',
            'name' => 'sometimes|required|string|max:255',
            'details' => 'nullable|string',
            'standard_price' => 'nullable|numeric|min:0',
            'status' => 'in:active,inactive',
        ], ['partner_id.required' => 'Select the Supplier Partner for this product.']);

        if ($request->user()->isPartnerUser()) {
            // A supplier can't hand their product over to another supplier.
            unset($data['partner_id']);
        } elseif (array_key_exists('partner_id', $data)) {
            $partner = $this->supplierPartner($data['partner_id']);
            $data['business_id'] = $partner->business_id;
        }

        $supplierProduct->update($data);

        return $this->withSupplierCategory($supplierProduct->load(self::PARTNER_WITH_CATEGORY));
    }

    public function destroy(Request $request, SupplierProduct $supplierProduct)
    {
        $this->authorizeOwnership($request, $supplierProduct);

        $supplierProduct->delete();

        return response()->json(null, 204);
    }

    /** The partner must hold the Supplier role (primary type or a live enrollment) — same rule as the Supplier dropdowns. */
    protected function supplierPartner(int|string $partnerId): Partner
    {
        $partner = Partner::whereKey($partnerId)
            ->where(fn ($q) => $q->whereHas('partnerType', fn ($t) => $t->where('code', 'supplier'))
                ->orWhereHas('enrollments', fn ($e) => $e->whereNotIn('status', ['suspended', 'rejected', 'inactive'])
                    ->whereHas('partnerType', fn ($t) => $t->where('code', 'supplier'))))
            ->first();

        abort_unless($partner, 422, 'The selected partner is not a Supplier Partner.');

        return $partner;
    }

    /** A partner-portal user may only touch their own catalog entries, never a general one or another supplier's. */
    protected function authorizeOwnership(Request $request, SupplierProduct $supplierProduct): void
    {
        $user = $request->user();
        abort_if($user->isPartnerUser() && $supplierProduct->partner_id !== $user->partner_id, 403, 'You can only manage your own catalog entries.');
    }
}
