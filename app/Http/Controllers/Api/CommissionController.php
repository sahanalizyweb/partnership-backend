<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ScopesToRequester;
use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Services\CommissionPaymentService;
use Illuminate\Http\Request;

class CommissionController extends Controller
{
    use ScopesToRequester;

    public function index(Request $request)
    {
        $query = Commission::with([
            'partner:id,name,partner_code', 'assignment:id,reference_number,title,assignment_type,payment_direction',
            'sale:id,reference_number,product_name,sale_amount,payment_status,amount_paid', 'payments:id,commission_id,reference_number,payment_date,payment_method,amount',
        ]);
        $query = $this->scopeBusiness($query, $request);
        $query = $this->scopePartner($query, $request);

        foreach (['status', 'partner_id', 'direction'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        return $query->orderByDesc('id')->paginate($request->integer('per_page', 20));
    }

    public function show(Commission $commission)
    {
        return $commission->load('partner', 'assignment', 'commissionRule', 'approvedBy');
    }

    public function approve(Request $request, Commission $commission, CommissionPaymentService $service)
    {
        $service->approve($commission, $request->user()->id);

        return $commission->fresh(['partner']);
    }

    /** Pay the partner (or record what the partner paid us) for this commission — one step. */
    public function recordPayment(Request $request, Commission $commission, CommissionPaymentService $service)
    {
        $data = $request->validate([
            'payment_date' => 'required|date',
            'payment_method' => 'nullable|string|max:100',
            'reference_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $payment = $service->recordPayment($commission, $data, $request->user()->id);

        return response()->json(['commission' => $commission->fresh(['partner', 'payments']), 'payment' => $payment], 201);
    }

    public function reject(Request $request, Commission $commission)
    {
        $data = $request->validate(['notes' => 'nullable|string']);

        abort_unless($commission->status === 'pending', 422, 'Only pending commissions can be rejected.');

        $commission->update(['status' => 'rejected', 'notes' => $data['notes'] ?? $commission->notes]);

        return $commission;
    }

    /** Manual adjustment to a commission amount before approval. */
    public function update(Request $request, Commission $commission)
    {
        abort_unless($commission->status === 'pending', 422, 'Only pending commissions can be adjusted.');

        $data = $request->validate([
            'commission_amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $commission->update($data);

        return $commission;
    }
}
