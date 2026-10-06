<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\Commission;
use App\Models\CommissionRule;
use App\Models\Partner;
use App\Models\Sale;

/**
 * Resolves the applicable commission_rule for a partner (product-specific rule for this
 * partner or their category takes precedence over the partner's own general rule, which
 * takes precedence over category-level, which takes precedence over type-level) and
 * computes a commissions row from a completed assignment/sale.
 */
class CommissionCalculationService
{
    public function resolveRule(Partner $partner, ?string $onDate = null, ?string $productRef = null): ?CommissionRule
    {
        $date = $onDate ?? now()->toDateString();

        $query = fn () => CommissionRule::query()
            ->where('business_id', $partner->business_id)
            ->where('status', 'active')
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date));

        if ($productRef) {
            $productRule = $query()->where('partner_id', $partner->id)->where('rule_type', 'product')->where('product_ref', $productRef)->first()
                ?? $query()->where('partner_category_id', $partner->partner_category_id)->where('rule_type', 'product')->where('product_ref', $productRef)->first();

            if ($productRule) {
                return $productRule;
            }
        }

        return $query()->where('partner_id', $partner->id)->first()
            ?? $query()->where('partner_category_id', $partner->partner_category_id)->first()
            ?? $query()->where('partner_type_id', $partner->partner_type_id)->first();
    }

    public function calculateAmount(CommissionRule $rule, float $baseAmount): float
    {
        return match ($rule->rule_type) {
            'percentage', 'category', 'product' => round($baseAmount * ((float) $rule->value / 100), 2),
            'fixed' => (float) $rule->value,
            'slab' => $this->calculateSlabAmount($rule, $baseAmount),
            default => 0.0,
        };
    }

    protected function calculateSlabAmount(CommissionRule $rule, float $baseAmount): float
    {
        $slab = $rule->slabs()
            ->where('min_value', '<=', $baseAmount)
            ->where(fn ($q) => $q->whereNull('max_value')->orWhere('max_value', '>=', $baseAmount))
            ->first();

        return $slab ? round($baseAmount * ((float) $slab->rate / 100), 2) : 0.0;
    }

    public function generateForAssignment(Assignment $assignment): ?Commission
    {
        if (! $assignment->partner_id || ! $assignment->transaction_amount) {
            return null;
        }

        // One commission per job — completing/delivering twice must not pay twice.
        if (Commission::where('assignment_id', $assignment->id)->where('status', '!=', 'rejected')->exists()) {
            return null;
        }

        $baseAmount = (float) $assignment->transaction_amount;

        // A service job with a commission % but no payment flow chosen: the normal case is the
        // customer pays us and we pay the partner their share.
        if ($assignment->assignment_type === 'service' && ! $assignment->payment_direction && $assignment->commission_percent !== null) {
            $assignment->payment_direction = 'customer_pays_lizyweb';
        }

        // Delivery (and any job with its own commission %): the partner earns that % of the
        // job amount, paid by us.
        if ($assignment->assignment_type !== 'service' && $assignment->commission_percent !== null) {
            return Commission::create([
                'business_id' => $assignment->business_id,
                'partner_id' => $assignment->partner_id,
                'partner_type_enrollment_id' => $assignment->partner_type_enrollment_id ?? $assignment->partner?->primaryEnrollment?->id,
                'business_source_id' => $assignment->business_source_id,
                'assignment_id' => $assignment->id,
                'transaction_reference' => $assignment->reference_number ?? 'ASSIGNMENT-'.$assignment->id,
                'base_amount' => $baseAmount,
                'commission_amount' => round($baseAmount * (float) $assignment->commission_percent / 100, 2),
                'direction' => 'to_partner',
                'status' => 'pending',
            ]);
        }

        // Service Partner's two payment-direction models (see assignments.payment_direction):
        // the rate is set per-assignment (assignments.commission_percent) rather than resolved
        // from the generic CommissionRule table, so the split is explicit and auditable.
        if ($assignment->assignment_type === 'service' && $assignment->payment_direction) {
            $rate = (float) ($assignment->commission_percent ?? 0);

            if ($assignment->payment_direction === 'customer_pays_partner') {
                // Model A: the partner already collected the full amount from the customer
                // directly, so they owe us the commission — a liability, not a payable.
                return Commission::create([
                    'business_id' => $assignment->business_id,
                    'partner_id' => $assignment->partner_id,
                    'partner_type_enrollment_id' => $assignment->partner_type_enrollment_id ?? $assignment->partner?->primaryEnrollment?->id,
                    'business_source_id' => $assignment->business_source_id,
                    'assignment_id' => $assignment->id,
                    'transaction_reference' => $assignment->reference_number ?? 'ASSIGNMENT-'.$assignment->id,
                    'base_amount' => $baseAmount,
                    'commission_amount' => round($baseAmount * $rate / 100, 2),
                    'direction' => 'from_partner',
                    'status' => 'pending',
                ]);
            }

            // Model B: we hold the full amount (customer paid us) and pay the partner their
            // share after taking our commission cut.
            return Commission::create([
                'business_id' => $assignment->business_id,
                'partner_id' => $assignment->partner_id,
                'partner_type_enrollment_id' => $assignment->partner_type_enrollment_id ?? $assignment->partner?->primaryEnrollment?->id,
                'business_source_id' => $assignment->business_source_id,
                'assignment_id' => $assignment->id,
                'transaction_reference' => $assignment->reference_number ?? 'ASSIGNMENT-'.$assignment->id,
                'base_amount' => $baseAmount,
                'commission_amount' => round($baseAmount * (100 - $rate) / 100, 2),
                'direction' => 'to_partner',
                'status' => 'pending',
            ]);
        }

        $rule = $this->resolveRule($assignment->partner, $assignment->completed_at?->toDateString());

        if (! $rule) {
            return null;
        }

        return Commission::create([
            'business_id' => $assignment->business_id,
            'partner_id' => $assignment->partner_id,
            'partner_type_enrollment_id' => $assignment->partner_type_enrollment_id ?? $assignment->partner?->primaryEnrollment?->id,
            'business_source_id' => $assignment->business_source_id,
            'assignment_id' => $assignment->id,
            'commission_rule_id' => $rule->id,
            'transaction_reference' => $assignment->reference_number ?? 'ASSIGNMENT-'.$assignment->id,
            'base_amount' => $baseAmount,
            'commission_amount' => $this->calculateAmount($rule, $baseAmount),
            'direction' => 'to_partner',
            'status' => 'pending',
        ]);
    }

    /**
     * Generates the commission for a product sale — the primary commission-earning event.
     * When the sale is against a catalog Product, that product's fixed standard commission %
     * always drives the amount (the Admin-defined rate for that Product/Service), unless
     * $ratePercentOverride (from the sale form) is given, which always wins. Only sales with
     * no catalog Product fall back to the legacy commission-rule lookup (partner/category/type),
     * kept for backward compatibility with existing free-text sales. Always creates a
     * Commission row (possibly ₹0, with no rule attached) so it's visible and can be adjusted
     * manually rather than silently dropped when nothing resolves.
     */
    public function generateForSale(Sale $sale, ?float $ratePercentOverride = null): Commission
    {
        $baseAmount = (float) $sale->sale_amount;
        $product = $sale->product;

        $fixedRate = $ratePercentOverride ?? ($product ? (float) $product->commission_percent : null);

        $rule = $fixedRate === null
            ? $this->resolveRule($sale->partner, $sale->sale_date?->toDateString(), $sale->product_name)
            : null;

        $commissionAmount = $fixedRate !== null
            ? round($baseAmount * ($fixedRate / 100), 2)
            : ($rule ? $this->calculateAmount($rule, $baseAmount) : 0.0);

        return Commission::create([
            'business_id' => $sale->business_id,
            'partner_id' => $sale->partner_id,
            'partner_type_enrollment_id' => $sale->partner_type_enrollment_id ?? $sale->partner?->primaryEnrollment?->id,
            'business_source_id' => $sale->business_source_id,
            'sale_id' => $sale->id,
            'commission_rule_id' => $rule?->id,
            'transaction_reference' => $sale->reference_number ?? 'SALE-'.$sale->id,
            'base_amount' => $baseAmount,
            'commission_amount' => $commissionAmount,
            'direction' => 'to_partner',
            'status' => 'pending',
        ]);
    }
}
