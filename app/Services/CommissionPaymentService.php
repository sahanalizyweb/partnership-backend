<?php

namespace App\Services;

use App\Events\CommissionApproved;
use App\Models\Commission;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * The one way commission money moves:
 *
 *   Waiting for approval (pending) -> Approved, money due (approved) -> Paid / Received (paid)
 *
 * direction 'to_partner'   = we pay the partner (sales/affiliate/referral commission, delivery
 *                            commission, service job where the customer paid us).
 * direction 'from_partner' = the partner pays us (service job where the customer paid the
 *                            partner directly, so they owe us our commission).
 *
 * Partner balance (ledger running_balance): positive = we owe the partner, negative = the
 * partner owes us. Approving adds what is owed; recording the payment clears it.
 */
class CommissionPaymentService
{
    public function __construct(
        protected LedgerPostingService $ledger,
        protected ReferenceNumberService $referenceNumbers,
    ) {}

    public function approve(Commission $commission, int $userId): Commission
    {
        abort_unless($commission->status === 'pending', 422, 'Only commissions waiting for approval can be approved.');
        $this->assertCustomerPaid($commission);

        $commission->update(['status' => 'approved', 'approved_by' => $userId, 'approved_at' => now()]);

        // Posts the amount owed to the partner's balance (PostLedgerOnCommissionApproved).
        event(new CommissionApproved($commission));

        return $commission;
    }

    /** Records the full commission amount as paid to (or received from) the partner. */
    public function recordPayment(Commission $commission, array $data, int $userId): Payment
    {
        abort_if($commission->status === 'rejected', 422, 'This commission was rejected — nothing to pay.');
        abort_if($commission->status === 'paid', 422, 'This commission is already paid.');
        abort_unless((float) $commission->commission_amount > 0, 422, 'This commission is ₹0 — nothing to pay.');
        $this->assertCustomerPaid($commission);

        return DB::transaction(function () use ($commission, $data, $userId) {
            // Paying straight from "waiting for approval" approves it in the same step.
            if ($commission->status === 'pending') {
                $this->approve($commission, $userId);
            }

            $payment = Payment::create([
                'business_id' => $commission->business_id,
                'partner_id' => $commission->partner_id,
                'assignment_id' => $commission->assignment_id,
                'commission_id' => $commission->id,
                'payment_type' => 'commission',
                'direction' => $commission->direction ?? 'to_partner',
                'amount' => $commission->commission_amount,
                'reference_number' => ($data['reference_number'] ?? null) ?: $this->referenceNumbers->next('PAY', $commission->business_id),
                'payment_date' => $data['payment_date'],
                'payment_method' => $data['payment_method'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'completed',
                'created_by' => $userId,
            ]);

            $this->postPayment($payment);
            $commission->update(['status' => 'paid']);

            return $payment;
        });
    }

    /**
     * A sale's commission only becomes payable once the customer has paid the sale in full.
     * Settlements only pick up approved commissions, so blocking approval here also keeps an
     * unpaid sale's commission out of any settlement. Sales without payment tracking (older
     * sales, referrals) and job commissions are unaffected.
     */
    protected function assertCustomerPaid(Commission $commission): void
    {
        $sale = $commission->sale;

        if ($sale && ! $sale->isCustomerFullyPaid()) {
            abort(422, sprintf(
                'The customer hasn\'t fully paid this sale yet (₹%s of ₹%s received) — the commission becomes payable once the sale is fully paid.',
                number_format((float) $sale->amount_paid, 2),
                number_format((float) $sale->sale_amount, 2),
            ));
        }
    }

    /**
     * Money we send the partner lowers what we owe them (debit); money the partner sends us
     * lowers what they owe us (credit).
     */
    public function postPayment(Payment $payment): void
    {
        $toPartner = $payment->direction === 'to_partner';

        $this->ledger->post(
            partner: $payment->partner,
            transactionType: $payment->payment_type,
            debit: $toPartner ? (float) $payment->amount : 0,
            credit: $toPartner ? 0 : (float) $payment->amount,
            description: ($toPartner ? 'Paid to partner' : 'Received from partner').' — '.$payment->reference_number,
            referenceType: Payment::class,
            referenceId: $payment->id,
            entryDate: $payment->payment_date?->toDateString(),
        );
    }
}
