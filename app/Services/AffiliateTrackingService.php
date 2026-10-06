<?php

namespace App\Services;

use App\Models\AffiliateLink;
use App\Models\Commission;
use App\Models\Sale;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The storefront side of Method A attribution: link visit → click, order → Sale + conversion,
 * paid → commission. Every step is idempotent, because browsers refresh and the shop re-sends
 * webhooks: a visitor counts once per link per day, an order (by the shop's order id) becomes
 * at most one Sale / one conversion, and a Sale gets at most one commission.
 */
class AffiliateTrackingService
{
    public function __construct(protected CommissionCalculationService $calculator) {}

    public function findActiveLink(?string $code): ?AffiliateLink
    {
        $code = trim((string) $code);

        return $code === '' ? null : AffiliateLink::where('code', $code)->where('status', 'active')->first();
    }

    /** Returns true only if this visit was counted (i.e. not a repeat from the same visitor today). */
    public function recordClick(AffiliateLink $link, ?string $visitorId, ?string $ip, ?string $userAgent): bool
    {
        // The shop's script sends a random per-browser id; without one, fall back to IP + browser.
        $visitorHash = hash('sha256', $visitorId ? 'v:'.$visitorId : 'ip:'.$ip.'|'.$userAgent);

        $inserted = DB::table('affiliate_clicks')->insertOrIgnore([
            'affiliate_link_id' => $link->id,
            'visitor_hash' => $visitorHash,
            'click_date' => now()->toDateString(),
            'ip' => $ip,
            'user_agent' => $userAgent ? mb_substr($userAgent, 0, 512) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted) {
            $link->increment('clicks_count');
        }

        return (bool) $inserted;
    }

    /**
     * A shop order placed with an affiliate ref: creates the pending Sale linked to the link's
     * partner and counts the conversion. No commission yet — that waits for payment
     * (markPaid). Re-sending the same order returns the existing Sale untouched.
     */
    public function recordOrder(AffiliateLink $link, string $externalOrderId, array $order): Sale
    {
        if ($existing = Sale::where('external_order_id', $externalOrderId)->first()) {
            return $existing;
        }

        $partner = $link->partner;
        $amount = round((float) $order['amount'], 2);

        try {
            return DB::transaction(function () use ($link, $partner, $externalOrderId, $order, $amount) {
                $sale = Sale::create([
                    'business_id' => $partner->business_id,
                    'partner_id' => $partner->id,
                    'partner_type_enrollment_id' => $link->partner_type_enrollment_id ?? $partner->primaryEnrollment?->id,
                    'attribution_method' => 'affiliate_link',
                    'sale_type' => 'sale',
                    'affiliate_link_id' => $link->id,
                    'product_id' => $link->product_id,
                    'product_name' => $link->product?->name ?? 'Shop order',
                    'customer_name' => $order['customer_name'] ?? null,
                    'customer_phone' => $order['customer_phone'] ?? null,
                    'unit_price' => $amount,
                    'quantity' => 1,
                    'sale_amount' => $amount,
                    'sale_date' => $order['date'] ?? now()->toDateString(),
                    'status' => 'pending',
                    'payment_status' => 'unpaid',
                    'amount_paid' => 0,
                    'reference_number' => $order['reference'] ?? null,
                    'external_order_id' => $externalOrderId,
                    'notes' => 'Auto-recorded from shop order via affiliate link '.$link->code,
                ]);

                $link->increment('conversions_count');

                return $sale;
            });
        } catch (QueryException $e) {
            // Two copies of the same webhook raced, or this order number was already attributed
            // manually — either way it's already recorded; never count it twice.
            return Sale::where('external_order_id', $externalOrderId)->first()
                ?? Sale::where('reference_number', $order['reference'] ?? null)->firstOrFail();
        }
    }

    /** Order paid/completed: confirm the Sale and create its commission once, at the link's commission %. */
    public function markPaid(Sale $sale): ?Commission
    {
        if ($sale->status === 'cancelled') {
            return null;
        }

        return DB::transaction(function () use ($sale) {
            $sale = Sale::lockForUpdate()->findOrFail($sale->id);

            if ($sale->status !== 'confirmed' || $sale->payment_status !== 'paid') {
                // The shop reported the order paid: the customer has paid the sale in full.
                $sale->update(['status' => 'confirmed', 'payment_status' => 'paid', 'amount_paid' => $sale->sale_amount]);
            }

            $existing = Commission::where('sale_id', $sale->id)->where('status', '!=', 'rejected')->first();
            if ($existing) {
                return $existing;
            }

            // Same rate rule as a manually recorded affiliate sale (SaleController::store): the
            // % verified on the link, else the product's standard % / commission rules.
            $rate = $sale->affiliateLink?->commission_percent;

            return $this->calculator->generateForSale($sale, $rate !== null ? (float) $rate : null);
        });
    }

    /** Order cancelled/refunded before payout: cancel the Sale and reject a still-pending commission. */
    public function markCancelled(Sale $sale): void
    {
        $sale->update(['status' => 'cancelled']);
        Commission::where('sale_id', $sale->id)->where('status', 'pending')->update(['status' => 'rejected']);
    }
}
