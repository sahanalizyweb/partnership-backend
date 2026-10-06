<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ScopesToRequester;
use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\Payment;
use App\Services\CommissionPaymentService;
use App\Services\LedgerPostingService;
use App\Services\ReferenceNumberService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    use ScopesToRequester;

    public function __construct(protected ReferenceNumberService $referenceNumbers) {}

    public function index(Request $request)
    {
        $query = Payment::with('partner', 'assignment');
        $query = $this->scopeBusiness($query, $request);
        $query = $this->scopePartner($query, $request);

        foreach (['status', 'payment_type', 'partner_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }
        if ($request->filled('assignment_type')) {
            // Payments for that kind of work: linked to such an assignment, or (unlinked)
            // made to a partner holding the matching role (service / delivery).
            $type = $request->string('assignment_type');
            $query->where(fn ($q) => $q
                ->whereHas('assignment', fn ($a) => $a->where('assignment_type', $type))
                ->orWhere(fn ($unlinked) => $unlinked->whereNull('assignment_id')
                    ->whereHas('partner.enrollments.partnerType', fn ($t) => $t->where('code', $type))));
        }

        return $query->orderByDesc('id')->paginate($request->integer('per_page', 20));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'partner_id' => 'required|exists:partners,id',
            'assignment_id' => 'nullable|exists:assignments,id',
            'payment_type' => 'required|in:commission,advance,refund,adjustment,settlement',
            'direction' => 'required|in:to_partner,from_partner',
            'amount' => 'required|numeric|min:0.01',
            'reference_number' => 'nullable|string|max:255',
            'payment_date' => 'required|date',
            'payment_method' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
        ]);

        $partner = Partner::findOrFail($data['partner_id']);

        if (! empty($data['assignment_id'])) {
            $assignment = \App\Models\Assignment::findOrFail($data['assignment_id']);
            abort_if($assignment->partner_id !== $partner->id, 422, 'That assignment does not belong to the selected partner.');
        }

        $data['reference_number'] = $data['reference_number'] ?? $this->referenceNumbers->next('PAY', $partner->business_id);

        // Recording a payment means the money has moved — it is final straight away and
        // updates the partner's balance (no separate "complete" step).
        $payment = Payment::create($data + [
            'business_id' => $partner->business_id,
            'status' => 'completed',
            'created_by' => $request->user()->id,
        ]);
        app(CommissionPaymentService::class)->postPayment($payment);

        return response()->json($payment->load('partner', 'assignment'), 201);
    }

    public function show(Payment $payment)
    {
        return $payment->load('partner', 'assignment', 'settlement', 'createdBy');
    }

    /**
     * Mark a payment completed and post its effect to the ledger. Payments settling an
     * amount already owed to the partner (commission/settlement/advance sent to_partner,
     * or a refund/adjustment received from_partner) are debits that reduce the payable
     * balance; amounts newly owed to the partner (a refund/adjustment sent to_partner)
     * are credits.
     */
    public function complete(Request $request, Payment $payment, LedgerPostingService $ledger)
    {
        abort_unless($payment->status === 'pending', 422, 'Only pending payments can be completed.');

        $payment->update(['status' => 'completed']);

        // Same rule everywhere: paid to partner lowers what we owe; received lowers what they owe.
        app(CommissionPaymentService::class)->postPayment($payment);

        return $payment->fresh('partner');
    }

    public function cancel(Payment $payment)
    {
        abort_unless($payment->status === 'pending', 422, 'Only pending payments can be cancelled.');

        $payment->update(['status' => 'cancelled']);

        return $payment;
    }
}
