<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ScopesToRequester;
use App\Http\Controllers\Controller;
use App\Models\ResellerPayment;
use App\Models\ResellerPurchase;
use App\Services\ReferenceNumberService;
use Illuminate\Http\Request;

/**
 * Payments a Reseller Partner made to us against a specific ResellerPurchase/invoice.
 * Mirrors SupplierPaymentController — admin-only to create/delete; the reseller views their
 * own read-only history/balance in the portal.
 */
class ResellerPaymentController extends Controller
{
    use ScopesToRequester;

    public function __construct(protected ReferenceNumberService $referenceNumbers) {}

    public function index(Request $request)
    {
        $query = ResellerPayment::with(['partner', 'resellerPurchase']);
        $query = $this->scopeBusiness($query, $request);
        $query = $this->scopePartner($query, $request);

        foreach (['partner_id', 'reseller_purchase_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        return $query->orderByDesc('id')->paginate($request->integer('per_page', 20));
    }

    public function store(Request $request)
    {
        abort_if($request->user()->isPartnerUser(), 403, 'Only admin staff can record a reseller payment.');

        $data = $request->validate([
            'reseller_purchase_id' => 'required|exists:reseller_purchases,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_date' => 'required|date',
            'payment_method' => 'nullable|string|max:100',
            'reference_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $purchase = ResellerPurchase::findOrFail($data['reseller_purchase_id']);
        abort_if((float) $data['amount'] > (float) $purchase->balance_amount + 0.01, 422, 'Payment amount exceeds the outstanding balance for this purchase.');

        $data['reference_number'] = $data['reference_number'] ?? $this->referenceNumbers->next('RESPAY', $purchase->business_id);

        $payment = ResellerPayment::create($data + [
            'business_id' => $purchase->business_id,
            'partner_id' => $purchase->partner_id,
            'created_by' => $request->user()->id,
        ]);

        $purchase->increment('amount_paid', $data['amount']);
        $purchase->recalcPaymentStatus();

        return response()->json($payment->load('partner', 'resellerPurchase'), 201);
    }

    public function show(Request $request, ResellerPayment $resellerPayment)
    {
        $user = $request->user();
        abort_if($user->isPartnerUser() && $resellerPayment->partner_id !== $user->partner_id, 403, 'You can only view your own payments.');

        return $resellerPayment->load('partner', 'resellerPurchase', 'createdBy');
    }

    public function destroy(Request $request, ResellerPayment $resellerPayment)
    {
        abort_if($request->user()->isPartnerUser(), 403, 'Only admin staff can delete a reseller payment.');

        $purchase = $resellerPayment->resellerPurchase;
        $amount = (float) $resellerPayment->amount;
        $resellerPayment->delete();

        $purchase->decrement('amount_paid', $amount);
        $purchase->recalcPaymentStatus();

        return response()->json(null, 204);
    }
}
