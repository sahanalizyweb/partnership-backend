<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Services\AffiliateTrackingService;
use Illuminate\Http\Request;

/**
 * Receives Lizy Mart (Shopify) order webhooks and turns affiliate-referred orders into Sales:
 *   orders/create           → Sale (pending) + conversion
 *   orders/paid             → Sale confirmed + commission
 *   orders/cancelled        → Sale cancelled, pending commission rejected
 *
 * The affiliate ref reaches the order as the cart attribute "affiliate_ref" (set by the
 * storefront script in shopify/lizymart-affiliate-tracking.liquid), with the ?ref= on the
 * order's landing page as a fallback. Every request is verified with the shop's webhook secret.
 */
class ShopifyWebhookController extends Controller
{
    public function __construct(protected AffiliateTrackingService $tracking) {}

    public function orders(Request $request)
    {
        $secret = config('services.shopify.webhook_secret');
        abort_unless($secret, 503, 'Shopify webhook secret is not configured.');

        $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));
        abort_unless(hash_equals($expected, (string) $request->header('X-Shopify-Hmac-Sha256')), 401, 'Invalid webhook signature.');

        $order = $request->json()->all();
        $orderId = (string) ($order['id'] ?? '');
        abort_if($orderId === '', 422, 'Missing order id.');

        $topic = (string) $request->header('X-Shopify-Topic');
        $sale = Sale::where('external_order_id', $orderId)->first();

        if ($topic === 'orders/cancelled') {
            if ($sale) {
                $this->tracking->markCancelled($sale);
            }

            return response()->json(['ok' => true, 'sale_id' => $sale?->id]);
        }

        // orders/create, orders/paid, orders/updated: record the Sale if this order carries a
        // ref (and isn't recorded yet) — so a missed orders/create is caught up by orders/paid.
        if (! $sale) {
            $link = $this->tracking->findActiveLink($this->affiliateRef($order));
            if (! $link) {
                return response()->json(['ok' => true, 'affiliate' => false]);
            }
            $sale = $this->tracking->recordOrder($link, $orderId, $this->orderDetails($order));
        }

        $paid = $topic === 'orders/paid' || ($order['financial_status'] ?? null) === 'paid';
        $commission = $paid ? $this->tracking->markPaid($sale) : null;

        return response()->json(['ok' => true, 'sale_id' => $sale->id, 'commission_id' => $commission?->id]);
    }

    protected function affiliateRef(array $order): ?string
    {
        foreach ($order['note_attributes'] ?? [] as $attr) {
            if (in_array($attr['name'] ?? null, ['affiliate_ref', 'ref'], true) && ! empty($attr['value'])) {
                return (string) $attr['value'];
            }
        }

        // Fallback: the page the customer first landed on, e.g. /products/x?ref=CODE.
        foreach (['landing_site', 'landing_site_ref'] as $key) {
            $query = parse_url((string) ($order[$key] ?? ''), PHP_URL_QUERY);
            if ($query) {
                parse_str($query, $params);
                if (! empty($params['ref'])) {
                    return (string) $params['ref'];
                }
            }
        }

        return null;
    }

    /** Commission base = order subtotal after discounts, before shipping and tax. */
    protected function orderDetails(array $order): array
    {
        $customer = $order['customer'] ?? [];
        $name = trim(($customer['first_name'] ?? '').' '.($customer['last_name'] ?? ''));

        return [
            'amount' => $order['current_subtotal_price'] ?? $order['subtotal_price'] ?? $order['total_price'] ?? 0,
            // The shop's order number (e.g. "#1001") — same as an admin would type on a manual
            // sale, so SaleController's "already attributed" guard also blocks a manual duplicate.
            'reference' => ! empty($order['name']) ? (string) $order['name'] : null,
            'date' => isset($order['created_at']) ? substr((string) $order['created_at'], 0, 10) : null,
            'customer_name' => $name !== '' ? $name : null,
            'customer_phone' => $order['phone'] ?? $customer['phone'] ?? null,
        ];
    }
}
