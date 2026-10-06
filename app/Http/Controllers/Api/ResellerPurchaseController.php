<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ScopesToRequester;
use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\Product;
use App\Models\ResellerProductPrice;
use App\Models\ResellerPurchase;
use App\Services\ReferenceNumberService;
use Illuminate\Http\Request;

/**
 * Reseller Partner purchases — the Lizyweb -> Reseller -> Customer flow: what a reseller
 * bought from our own `products` catalog to resell. Mirrors SupplyController exactly, except
 * the money owed runs the other way (the reseller owes us, not us owing them) — see
 * DashboardController::resellerDashboard. A reseller can self-report their own purchases from
 * the portal, but only while nothing has been paid yet, same rule as Supply.
 */
class ResellerPurchaseController extends Controller
{
    use ScopesToRequester;

    public function __construct(protected ReferenceNumberService $referenceNumbers) {}

    public function index(Request $request)
    {
        $query = ResellerPurchase::with(['partner', 'product']);
        $query = $this->scopeBusiness($query, $request);
        $query = $this->scopePartner($query, $request);

        if ($request->filled('partner_id')) {
            $query->where('partner_id', $request->integer('partner_id'));
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->input('payment_status'));
        }

        return $query->orderByDesc('id')->paginate($request->integer('per_page', 20));
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'partner_id' => 'nullable|exists:partners,id',
            'product_id' => 'nullable|exists:products,id',
            'product_name' => 'required_without:product_id|string|max:255',
            'quantity' => 'required|integer|min:1',
            'unit_price' => 'nullable|numeric|min:0',
            'purchase_date' => 'required|date',
            'resale_customer_name' => 'nullable|string|max:255',
            'resale_amount' => 'nullable|numeric|min:0',
            'resale_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        // A partner-portal user (the reseller) can only ever log a purchase for themselves.
        if ($user->isPartnerUser()) {
            $data['partner_id'] = $user->partner_id;
        }
        abort_unless($data['partner_id'] ?? null, 422, 'A reseller is required.');
        abort_if(! isset($data['unit_price']) && empty($data['product_id']), 422, 'unit_price is required when no catalog product/reseller pricing applies.');

        $partner = Partner::findOrFail($data['partner_id']);
        $product = ! empty($data['product_id']) ? Product::findOrFail($data['product_id']) : null;

        $resellerEnrollment = $partner->enrollments()
            ->whereHas('partnerType', fn ($q) => $q->where('code', 'reseller'))
            ->first();

        // Requirement #19: default the purchase price from configured reseller pricing when
        // one exists for this enrollment+product, otherwise fall back to what was submitted.
        $configuredPrice = $product && $resellerEnrollment
            ? ResellerProductPrice::where('partner_type_enrollment_id', $resellerEnrollment->id)->where('product_id', $product->id)->value('purchase_price')
            : null;

        $unitPrice = (float) ($configuredPrice ?? $data['unit_price'] ?? ($product?->amount ?? 0));
        $quantity = (int) $data['quantity'];

        $purchase = ResellerPurchase::create([
            'business_id' => $partner->business_id,
            'partner_id' => $partner->id,
            'partner_type_enrollment_id' => $resellerEnrollment?->id ?? $partner->primaryEnrollment?->id,
            'product_id' => $product?->id,
            'reference_number' => $this->referenceNumbers->next('RES', $partner->business_id),
            'product_name' => $product ? $product->name : $data['product_name'],
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'amount' => round($unitPrice * $quantity, 2),
            'purchase_date' => $data['purchase_date'],
            'amount_paid' => 0,
            'payment_status' => 'unpaid',
            'resale_customer_name' => $data['resale_customer_name'] ?? null,
            'resale_amount' => $data['resale_amount'] ?? null,
            'resale_date' => $data['resale_date'] ?? null,
            'resale_status' => ! empty($data['resale_customer_name']) ? 'resold' : 'not_resold',
            'notes' => $data['notes'] ?? null,
            'created_by' => $user->id,
        ]);

        return response()->json($purchase->load('partner', 'product'), 201);
    }

    public function show(Request $request, ResellerPurchase $resellerPurchase)
    {
        $user = $request->user();
        abort_if($user->isPartnerUser() && $resellerPurchase->partner_id !== $user->partner_id, 403, 'You can only view your own purchases.');

        return $resellerPurchase->load('partner', 'product', 'payments', 'createdBy');
    }

    public function update(Request $request, ResellerPurchase $resellerPurchase)
    {
        abort_if($request->user()->isPartnerUser(), 403, 'Only admin staff can edit purchase details.');

        $data = $request->validate([
            'product_id' => 'nullable|exists:products,id',
            'product_name' => 'sometimes|required|string|max:255',
            'quantity' => 'sometimes|required|integer|min:1',
            'unit_price' => 'sometimes|required|numeric|min:0',
            'purchase_date' => 'sometimes|required|date',
            'resale_customer_name' => 'nullable|string|max:255',
            'resale_amount' => 'nullable|numeric|min:0',
            'resale_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $this->applyPurchaseUpdate($resellerPurchase, $data);

        return $resellerPurchase->fresh(['partner', 'product', 'payments']);
    }

    public function destroy(Request $request, ResellerPurchase $resellerPurchase)
    {
        abort_if($request->user()->isPartnerUser(), 403, 'Only admin staff can delete a purchase.');

        $resellerPurchase->delete();

        return response()->json(null, 204);
    }

    /** Partner Portal self-service edit of a purchase the reseller logged — mirrors SupplyController::updateMine. */
    public function updateMine(Request $request, ResellerPurchase $resellerPurchase)
    {
        $user = $request->user();
        abort_unless($user->isPartnerUser(), 403, 'This endpoint is for partner-portal users only.');
        abort_if($resellerPurchase->partner_id !== $user->partner_id, 403, 'You can only edit your own purchases.');
        abort_unless($this->isEditableByPartner($resellerPurchase), 422, 'This purchase can no longer be edited — a payment has already been recorded against it.');

        $data = $request->validate([
            'product_id' => 'nullable|exists:products,id',
            'product_name' => 'sometimes|required|string|max:255',
            'quantity' => 'sometimes|required|integer|min:1',
            'unit_price' => 'sometimes|required|numeric|min:0',
            'purchase_date' => 'sometimes|required|date',
            'resale_customer_name' => 'nullable|string|max:255',
            'resale_amount' => 'nullable|numeric|min:0',
            'resale_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $this->applyPurchaseUpdate($resellerPurchase, $data);

        return $resellerPurchase->fresh(['partner', 'product', 'payments']);
    }

    public function destroyMine(Request $request, ResellerPurchase $resellerPurchase)
    {
        $user = $request->user();
        abort_unless($user->isPartnerUser(), 403, 'This endpoint is for partner-portal users only.');
        abort_if($resellerPurchase->partner_id !== $user->partner_id, 403, 'You can only delete your own purchases.');
        abort_unless($this->isEditableByPartner($resellerPurchase), 422, 'This purchase can no longer be deleted — a payment has already been recorded against it.');

        $resellerPurchase->delete();

        return response()->json(null, 204);
    }

    protected function isEditableByPartner(ResellerPurchase $purchase): bool
    {
        return $purchase->payment_status === 'unpaid' && (float) $purchase->amount_paid <= 0;
    }

    protected function applyPurchaseUpdate(ResellerPurchase $purchase, array $data): void
    {
        if (array_key_exists('product_id', $data)) {
            $product = $data['product_id'] ? Product::findOrFail($data['product_id']) : null;
            $data['product_id'] = $product?->id;
            if ($product) {
                $data['product_name'] = $product->name;
            }
        }

        if (array_key_exists('resale_customer_name', $data)) {
            $data['resale_status'] = ! empty($data['resale_customer_name']) ? 'resold' : 'not_resold';
        }

        $quantity = $data['quantity'] ?? $purchase->quantity;
        $unitPrice = $data['unit_price'] ?? $purchase->unit_price;
        $data['amount'] = round($quantity * $unitPrice, 2);

        $purchase->update($data);
        $purchase->recalcPaymentStatus();
    }
}
