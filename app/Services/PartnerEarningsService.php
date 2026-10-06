<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\Partner;
use App\Models\PartnerTypeEnrollment;
use App\Models\ResellerPurchase;
use Illuminate\Database\Eloquent\Builder;

/**
 * Backend-calculated "Total Partner Earnings" — never computed on the frontend. Aggregates
 * across every active/other enrollment a partner holds, keeping each type's underlying
 * transactions completely separate while summing them into one overall total. Supplier is
 * deliberately excluded: supply/payment accounting is a payable, not an earning.
 */
class PartnerEarningsService
{
    public function summary(Partner $partner): array
    {
        $enrollments = $partner->enrollments()->with('partnerType')->get();

        $byType = [];
        $totals = ['total_revenue' => 0.0, 'total_earnings' => 0.0, 'paid_earnings' => 0.0, 'pending_earnings' => 0.0];

        foreach ($enrollments as $enrollment) {
            $code = $enrollment->partnerType?->code;

            if ($code === 'supplier' || ! $code) {
                continue;
            }

            $row = $code === 'reseller'
                ? $this->resellerBreakdown($enrollment)
                : $this->commissionBreakdown($enrollment);

            $byType[$code] = $row + [
                'partner_type_id' => $enrollment->partner_type_id,
                'partner_type_name' => $enrollment->partnerType?->name,
                'enrollment_id' => $enrollment->id,
                'enrollment_status' => $enrollment->status,
            ];

            $totals['total_revenue'] += $row['revenue'];
            $totals['total_earnings'] += $row['earnings_total'];
            $totals['paid_earnings'] += $row['earnings_paid'];
            $totals['pending_earnings'] += $row['earnings_pending'];
        }

        $totals['available_earnings'] = round($totals['total_earnings'] - $totals['paid_earnings'], 2);
        $totals['total_transactions'] = $this->transactionCount($partner);
        $totals['active_partner_types'] = $enrollments->where('status', 'active')->count();

        return [
            'overall' => array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $totals),
            'by_type' => $byType,
        ];
    }

    /**
     * Rows scoped to this enrollment, plus — only for the partner's PRIMARY enrollment —
     * legacy rows (partner_type_enrollment_id still null, created before this migration/
     * backfill ran) matched by partner_id. Restricting the legacy fallback to is_primary
     * matters: those null rows could only ever have meant the partner's one original type, so
     * if a partner later gains a *second* enrollment, the fallback must not also match there —
     * otherwise the same legacy commission would be counted once per enrollment, inflating
     * Total Partner Earnings. The OR condition is wrapped in a single group so a later
     * ->where('status', ...) ANDs against the whole thing rather than only the second branch.
     */
    protected function scopedCommissions(PartnerTypeEnrollment $enrollment): Builder
    {
        return Commission::where(function ($q) use ($enrollment) {
            $q->where('partner_type_enrollment_id', $enrollment->id);

            if ($enrollment->is_primary) {
                $q->orWhere(function ($legacy) use ($enrollment) {
                    $legacy->whereNull('partner_type_enrollment_id')
                        ->where('partner_id', $enrollment->partner_id)
                        ->where('direction', 'to_partner');
                });
            }
        });
    }

    protected function commissionBreakdown(PartnerTypeEnrollment $enrollment): array
    {
        $base = $this->scopedCommissions($enrollment);

        return [
            'revenue' => (float) (clone $base)->sum('base_amount'),
            'earnings_total' => (float) (clone $base)->where('status', '!=', 'rejected')->sum('commission_amount'),
            'earnings_paid' => (float) (clone $base)->where('status', 'paid')->sum('commission_amount'),
            'earnings_pending' => (float) (clone $base)->whereIn('status', ['pending', 'approved', 'payable'])->sum('commission_amount'),
        ];
    }

    /** Same is_primary-only legacy fallback rationale as scopedCommissions() above. */
    protected function scopedResellerPurchases(PartnerTypeEnrollment $enrollment): Builder
    {
        return ResellerPurchase::where(function ($q) use ($enrollment) {
            $q->where('partner_type_enrollment_id', $enrollment->id);

            if ($enrollment->is_primary) {
                $q->orWhere(function ($legacy) use ($enrollment) {
                    $legacy->whereNull('partner_type_enrollment_id')->where('partner_id', $enrollment->partner_id);
                });
            }
        });
    }

    /**
     * Reseller margin (resale_amount - amount), not a commission. Realized the moment a
     * purchase is marked resold — the reseller collects it directly from their own customer,
     * so it never flows through the commission/payment pipeline; treated as already paid.
     */
    protected function resellerBreakdown(PartnerTypeEnrollment $enrollment): array
    {
        $resold = $this->scopedResellerPurchases($enrollment)->where('resale_status', 'resold')->get();
        $margin = (float) $resold->sum(fn ($p) => (float) $p->resale_amount - (float) $p->amount);

        return [
            'revenue' => (float) $resold->sum('resale_amount'),
            'earnings_total' => round($margin, 2),
            'earnings_paid' => round($margin, 2),
            'earnings_pending' => 0.0,
        ];
    }

    protected function transactionCount(Partner $partner): int
    {
        return Commission::where('partner_id', $partner->id)->count()
            + ResellerPurchase::where('partner_id', $partner->id)->where('resale_status', 'resold')->count();
    }

    /** Flat, date-sorted transaction feed — the audit trail behind every earnings figure. */
    public function transactions(Partner $partner, array $filters = []): array
    {
        $commissionsQuery = Commission::with(['partnerTypeEnrollment.partnerType', 'businessSource', 'sale', 'assignment'])
            ->where('partner_id', $partner->id);

        if (! empty($filters['business_source_id'])) {
            $commissionsQuery->where('business_source_id', $filters['business_source_id']);
        }
        if (! empty($filters['status'])) {
            $commissionsQuery->where('status', $filters['status']);
        }

        $commissions = $commissionsQuery->get()->map(fn ($c) => [
            'type' => 'commission',
            'date' => $c->created_at?->toDateString(),
            'enrollment_type' => $c->partnerTypeEnrollment?->partnerType?->code,
            'business_source' => $c->businessSource?->name,
            'revenue' => (float) $c->base_amount,
            'earning' => (float) $c->commission_amount,
            'status' => $c->status,
            'reference' => $c->transaction_reference,
        ]);

        $marginsQuery = ResellerPurchase::with(['partnerTypeEnrollment', 'businessSource'])
            ->where('partner_id', $partner->id)
            ->where('resale_status', 'resold');

        if (! empty($filters['business_source_id'])) {
            $marginsQuery->where('business_source_id', $filters['business_source_id']);
        }

        $margins = $marginsQuery->get()->map(fn ($p) => [
            'type' => 'reseller_margin',
            'date' => (string) $p->resale_date,
            'enrollment_type' => 'reseller',
            'business_source' => $p->businessSource?->name,
            'revenue' => (float) $p->resale_amount,
            'earning' => round((float) $p->resale_amount - (float) $p->amount, 2),
            'status' => 'paid',
            'reference' => $p->reference_number,
        ]);

        if (! empty($filters['partner_type']) && $filters['partner_type'] !== 'reseller') {
            $margins = collect();
        }
        if (! empty($filters['partner_type']) && $filters['partner_type'] === 'reseller') {
            $commissions = collect();
        }

        return $commissions->concat($margins)->sortByDesc('date')->values()->all();
    }
}
