<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ScopesToRequester;
use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\Supply;
use App\Models\SupplierProduct;
use App\Services\ReferenceNumberService;
use Illuminate\Http\Request;

/**
 * Supplies — the Supplier -> Our Company flow: what was received from a Supplier Partner
 * (quantity, amount, invoice #). A supplier can self-report their own deliveries from the
 * portal (mirrors Sales — see SaleController), but only while nothing has been paid against
 * it yet; once Admin records any payment, only Admin can edit/delete it, since amount/quantity
 * changes would desync the payment/balance already on record.
 */
class SupplyController extends Controller
{
    use ScopesToRequester;

    public function __construct(protected ReferenceNumberService $referenceNumbers) {}

    public function index(Request $request)
    {
        $query = Supply::with(['partner', 'supplierProduct']);
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
            'supplier_product_id' => 'nullable|exists:supplier_products,id',
            'product_name' => 'required_without:supplier_product_id|string|max:255',
            'product_details' => 'nullable|string',
            'quantity' => 'nullable|integer|min:1',
            'unit_price' => 'nullable|numeric|min:0',
            // Total supply value — worked out as quantity × unit price when a price is given.
            'amount' => 'required_without:unit_price|nullable|numeric|min:0',
            'supply_date' => 'required|date',
            'invoice_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ], ['amount.required_without' => 'Enter the Unit Price (or the Total Supply Amount).']);

        // A partner-portal user can only ever log a supply for themselves — mirrors
        // SaleController::store pinning partner_id for a partner-portal user.
        if ($user->isPartnerUser()) {
            $data['partner_id'] = $user->partner_id;
        }
        abort_unless($data['partner_id'] ?? null, 422, 'A supplier is required.');

        $partner = Partner::findOrFail($data['partner_id']);
        $product = ! empty($data['supplier_product_id']) ? SupplierProduct::findOrFail($data['supplier_product_id']) : null;
        $this->assertProductBelongsToSupplier($product, $partner->id);

        $supply = Supply::create([
            'business_id' => $partner->business_id,
            'partner_id' => $partner->id,
            'supplier_product_id' => $product?->id,
            'reference_number' => $this->referenceNumbers->next('SUPPLY', $partner->business_id),
            'product_name' => $product ? $product->name : $data['product_name'],
            'product_details' => $data['product_details'] ?? $product?->details,
            'quantity' => $data['quantity'] ?? 1,
            'unit_price' => $data['unit_price'] ?? null,
            'amount' => self::totalAmount($data['quantity'] ?? 1, $data['unit_price'] ?? null, $data['amount'] ?? null),
            'supply_date' => $data['supply_date'],
            'invoice_number' => $data['invoice_number'] ?? null,
            'amount_paid' => 0,
            'payment_status' => 'unpaid',
            'notes' => $data['notes'] ?? null,
            'created_by' => $user->id,
        ]);

        return response()->json($supply->load('partner', 'supplierProduct'), 201);
    }

    public function show(Request $request, Supply $supply)
    {
        $user = $request->user();
        abort_if($user->isPartnerUser() && $supply->partner_id !== $user->partner_id, 403, 'You can only view your own supplies.');

        return $supply->load('partner', 'supplierProduct', 'payments', 'createdBy');
    }

    public function update(Request $request, Supply $supply)
    {
        abort_if($request->user()->isPartnerUser(), 403, 'Only admin staff can edit supply details.');

        $data = $request->validate([
            'supplier_product_id' => 'nullable|exists:supplier_products,id',
            'product_name' => 'sometimes|required|string|max:255',
            'product_details' => 'nullable|string',
            'quantity' => 'nullable|integer|min:1',
            'unit_price' => 'nullable|numeric|min:0',
            'amount' => 'nullable|numeric|min:0',
            'supply_date' => 'sometimes|required|date',
            'invoice_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $this->applySupplyUpdate($supply, $data);

        return $supply->fresh(['partner', 'supplierProduct', 'payments']);
    }

    public function destroy(Request $request, Supply $supply)
    {
        abort_if($request->user()->isPartnerUser(), 403, 'Only admin staff can delete a supply.');

        $supply->delete();

        return response()->json(null, 204);
    }

    /**
     * Partner Portal self-service edit of a Supply they logged — mirrors
     * SaleController::updateMine. Only allowed while nothing has been paid against it yet
     * (payment_status still 'unpaid'), so a supplier can never alter a delivery Admin has
     * already started paying against.
     */
    public function updateMine(Request $request, Supply $supply)
    {
        $user = $request->user();
        abort_unless($user->isPartnerUser(), 403, 'This endpoint is for partner-portal users only.');
        abort_if($supply->partner_id !== $user->partner_id, 403, 'You can only edit your own supplies.');
        abort_unless($this->isEditableByPartner($supply), 422, 'This supply can no longer be edited — Admin has already recorded a payment against it.');

        $data = $request->validate([
            'supplier_product_id' => 'nullable|exists:supplier_products,id',
            'product_name' => 'sometimes|required|string|max:255',
            'product_details' => 'nullable|string',
            'quantity' => 'nullable|integer|min:1',
            'unit_price' => 'nullable|numeric|min:0',
            'amount' => 'nullable|numeric|min:0',
            'supply_date' => 'sometimes|required|date',
            'invoice_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $this->applySupplyUpdate($supply, $data);

        return $supply->fresh(['partner', 'supplierProduct', 'payments']);
    }

    public function destroyMine(Request $request, Supply $supply)
    {
        $user = $request->user();
        abort_unless($user->isPartnerUser(), 403, 'This endpoint is for partner-portal users only.');
        abort_if($supply->partner_id !== $user->partner_id, 403, 'You can only delete your own supplies.');
        abort_unless($this->isEditableByPartner($supply), 422, 'This supply can no longer be deleted — Admin has already recorded a payment against it.');

        $supply->delete();

        return response()->json(null, 204);
    }

    protected function isEditableByPartner(Supply $supply): bool
    {
        return $supply->payment_status === 'unpaid' && (float) $supply->amount_paid <= 0;
    }

    /** A supply may only use the supplier's own catalog entries or general (partner_id null) ones. */
    protected function assertProductBelongsToSupplier(?SupplierProduct $product, int $partnerId): void
    {
        abort_if(
            $product && $product->partner_id !== null && (int) $product->partner_id !== $partnerId,
            422,
            'This product belongs to another supplier — choose one of this supplier\'s products.'
        );
    }

    protected function applySupplyUpdate(Supply $supply, array $data): void
    {
        if (array_key_exists('supplier_product_id', $data)) {
            $product = $data['supplier_product_id'] ? SupplierProduct::findOrFail($data['supplier_product_id']) : null;
            $this->assertProductBelongsToSupplier($product, (int) $supply->partner_id);
            $data['supplier_product_id'] = $product?->id;
            if ($product) {
                $data['product_name'] = $product->name;
            }
        }

        // Keep the total in step with quantity × unit price whenever the supply has a price.
        $unitPrice = array_key_exists('unit_price', $data) ? $data['unit_price'] : $supply->unit_price;
        if ($unitPrice !== null || array_key_exists('amount', $data)) {
            $data['amount'] = self::totalAmount($data['quantity'] ?? $supply->quantity, $unitPrice, $data['amount'] ?? $supply->amount);
        }

        $supply->update($data);
        $supply->recalcPaymentStatus();
    }

    /** Total supply value: quantity × unit price when a price is known, else the amount entered. */
    protected static function totalAmount(int|string|null $quantity, int|float|string|null $unitPrice, int|float|string|null $amount): float
    {
        if ($unitPrice !== null && $unitPrice !== '') {
            return round(max((int) ($quantity ?: 1), 1) * (float) $unitPrice, 2);
        }

        return round((float) $amount, 2);
    }
}
