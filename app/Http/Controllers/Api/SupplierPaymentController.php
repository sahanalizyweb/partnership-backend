<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ScopesToRequester;
use App\Http\Controllers\Controller;
use App\Models\Supply;
use App\Models\SupplierPayment;
use App\Services\ReferenceNumberService;
use Illuminate\Http\Request;

/**
 * Payments we made to a Supplier Partner against a specific Supply/invoice. Admin-only to
 * create/delete (mirrors Supply); the supplier views their own read-only history/balance in
 * the portal. Deliberately separate from PaymentController — see supplier_payments migration.
 */
class SupplierPaymentController extends Controller
{
    use ScopesToRequester;

    public function __construct(protected ReferenceNumberService $referenceNumbers) {}

    public function index(Request $request)
    {
        $query = SupplierPayment::with(['partner', 'supply']);
        $query = $this->scopeBusiness($query, $request);
        $query = $this->scopePartner($query, $request);

        foreach (['partner_id', 'supply_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        return $query->orderByDesc('id')->paginate($request->integer('per_page', 20));
    }

    public function store(Request $request)
    {
        abort_if($request->user()->isPartnerUser(), 403, 'Only admin staff can record a supplier payment.');

        $data = $request->validate([
            'supply_id' => 'required|exists:supplies,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_date' => 'required|date',
            'payment_method' => 'nullable|string|max:100',
            'reference_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $supply = Supply::findOrFail($data['supply_id']);
        abort_if((float) $data['amount'] > (float) $supply->balance_amount + 0.01, 422, 'Payment amount exceeds the outstanding balance for this supply.');

        $data['reference_number'] = $data['reference_number'] ?? $this->referenceNumbers->next('SUPPAY', $supply->business_id);

        $payment = SupplierPayment::create($data + [
            'business_id' => $supply->business_id,
            'partner_id' => $supply->partner_id,
            'created_by' => $request->user()->id,
        ]);

        $supply->increment('amount_paid', $data['amount']);
        $supply->recalcPaymentStatus();

        return response()->json($payment->load('partner', 'supply'), 201);
    }

    public function show(Request $request, SupplierPayment $supplierPayment)
    {
        $user = $request->user();
        abort_if($user->isPartnerUser() && $supplierPayment->partner_id !== $user->partner_id, 403, 'You can only view your own payments.');

        return $supplierPayment->load('partner', 'supply', 'createdBy');
    }

    public function destroy(Request $request, SupplierPayment $supplierPayment)
    {
        abort_if($request->user()->isPartnerUser(), 403, 'Only admin staff can delete a supplier payment.');

        $supply = $supplierPayment->supply;
        $amount = (float) $supplierPayment->amount;
        $supplierPayment->delete();

        $supply->decrement('amount_paid', $amount);
        $supply->recalcPaymentStatus();

        return response()->json(null, 204);
    }
}
