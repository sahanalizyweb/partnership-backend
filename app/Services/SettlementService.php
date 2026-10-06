<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\Settlement;
use App\Models\SettlementItem;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates a partner's payable commissions and advances/refunds/adjustments for a
 * period into a settlement, and on approval posts the net amount to the ledger and
 * records the payable payment.
 */
class SettlementService
{
    public function __construct(protected ReferenceNumberService $referenceNumbers) {}

    public function run(Partner $partner, string $periodStart, string $periodEnd): Settlement
    {
        return DB::transaction(function () use ($partner, $periodStart, $periodEnd) {
            $commissions = Commission::where('partner_id', $partner->id)
                ->where('status', 'approved')
                ->whereBetween('approved_at', [$periodStart, $periodEnd.' 23:59:59'])
                ->get();

            // Split by direction: 'to_partner' (the normal case — commission owed to the
            // partner) vs 'from_partner' (Service Model A — the partner already collected
            // payment from the customer and owes this amount to us). See
            // CommissionCalculationService::generateForAssignment.
            $commissionsToPartner = $commissions->where('direction', 'to_partner');
            $commissionsFromPartner = $commissions->where('direction', 'from_partner');

            $adjustmentPayments = Payment::where('partner_id', $partner->id)
                ->whereIn('payment_type', ['advance', 'refund', 'adjustment'])
                ->where('status', 'completed')
                ->whereBetween('payment_date', [$periodStart, $periodEnd])
                ->get();

            $totalCommission = (float) $commissionsToPartner->sum('commission_amount');
            $totalCommissionOwedByPartner = (float) $commissionsFromPartner->sum('commission_amount');
            $totalAdvances = (float) $adjustmentPayments->where('payment_type', 'advance')->sum('amount');
            $totalRefunds = (float) $adjustmentPayments->where('payment_type', 'refund')->sum('amount');
            $totalAdjustments = (float) $adjustmentPayments->where('payment_type', 'adjustment')->sum('amount');

            $netPayable = $totalCommission - $totalCommissionOwedByPartner - $totalAdvances - $totalRefunds + $totalAdjustments;

            $settlement = Settlement::create([
                'business_id' => $partner->business_id,
                'partner_id' => $partner->id,
                'settlement_number' => $this->referenceNumbers->next('SET', $partner->business_id),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'total_commission' => $totalCommission,
                'total_commission_owed_by_partner' => $totalCommissionOwedByPartner,
                'total_advances' => $totalAdvances,
                'total_refunds' => $totalRefunds,
                'total_adjustments' => $totalAdjustments,
                'net_payable' => $netPayable,
                'status' => 'draft',
            ]);

            foreach ($commissions as $commission) {
                SettlementItem::create([
                    'settlement_id' => $settlement->id,
                    'source_type' => 'commission',
                    'source_id' => $commission->id,
                    'amount' => $commission->direction === 'from_partner' ? -$commission->commission_amount : $commission->commission_amount,
                    'description' => $commission->transaction_reference,
                ]);
                $commission->update(['status' => 'payable']);
            }

            foreach ($adjustmentPayments as $payment) {
                SettlementItem::create([
                    'settlement_id' => $settlement->id,
                    'source_type' => $payment->payment_type,
                    'source_id' => $payment->id,
                    'amount' => $payment->amount,
                    'description' => $payment->reference_number,
                ]);
            }

            return $settlement;
        });
    }

    public function approve(Settlement $settlement, int $approvedByUserId): Settlement
    {
        return DB::transaction(function () use ($settlement, $approvedByUserId) {
            $settlement->update([
                'status' => 'approved',
                'approved_by' => $approvedByUserId,
                'approved_at' => now(),
            ]);

            $commissionItems = $settlement->items()->where('source_type', 'commission')->get();
            foreach ($commissionItems as $item) {
                Commission::where('id', $item->source_id)->update(['status' => 'paid']);
            }

            Payment::create([
                'business_id' => $settlement->business_id,
                'partner_id' => $settlement->partner_id,
                'payment_type' => 'settlement',
                'direction' => $settlement->net_payable >= 0 ? 'to_partner' : 'from_partner',
                'amount' => abs($settlement->net_payable),
                'reference_number' => $settlement->settlement_number,
                'payment_date' => now()->toDateString(),
                'status' => 'pending',
                'related_settlement_id' => $settlement->id,
                'created_by' => $approvedByUserId,
            ]);

            return $settlement->fresh();
        });
    }
}
