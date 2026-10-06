<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Commission;
use App\Models\Partner;
use App\Models\Product;
use App\Models\ResellerPurchase;
use App\Models\Sale;
use App\Models\SupplierProduct;
use App\Models\Supply;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Top-bar global search: up to 5 matches per group. Each group needs the same `.view`
 * permission as its own list endpoint; staff are limited to their business (same rule as
 * ScopesToRequester), and partner-portal users only ever search their own records.
 */
class SearchController extends Controller
{
    protected const LIMIT = 5;

    public function index(Request $request)
    {
        $q = trim((string) $request->input('q'));
        if (mb_strlen($q) < 2) {
            return ['groups' => []];
        }

        $user = $request->user();
        $partnerId = $user->isPartnerUser() ? $user->partner_id : null;
        $base = $partnerId ? '/portal' : '/app';
        $term = '%'.addcslashes($q, '%_\\').'%';
        $groups = [];

        foreach ($this->groups($base) as $key => $group) {
            if (! $user->can($group['permission']) || ($partnerId && ! $group['portal'])) {
                continue;
            }

            $query = $group['query']();
            if ($user->business_id) {
                $query->where('business_id', $user->business_id);
            }
            if ($partnerId) {
                $query->where('partner_id', $partnerId);
            }

            $query->where(function (Builder $w) use ($group, $term) {
                foreach ($group['columns'] as $column) {
                    if (str_contains($column, '.')) {
                        [$relation, $field] = explode('.', $column, 2);
                        $w->orWhereHas($relation, fn ($r) => $r->where($field, 'like', $term));
                    } else {
                        $w->orWhere($column, 'like', $term);
                    }
                }
            });

            $items = $query->orderByDesc('id')->limit(self::LIMIT)->get()->map($group['item'])->values();
            if ($items->isNotEmpty()) {
                $groups[] = ['key' => $key, 'label' => $group['label'], 'items' => $items];
            }
        }

        return ['groups' => $groups];
    }

    /** Opens a list page with its table search pre-filled, so the matching row is the one shown. */
    protected static function listUrl(string $path, ?string $search): string
    {
        return $path.($search ? '?'.http_build_query(['search' => $search]) : '');
    }

    protected function groups(string $base): array
    {
        $portal = $base === '/portal';
        $item = fn ($id, $title, $subtitle, $url) => compact('id', 'title', 'subtitle', 'url');

        return [
            'partners' => [
                'label' => 'Partners', 'permission' => 'partners.view', 'portal' => false,
                'query' => fn () => Partner::query(),
                'columns' => ['name', 'partner_code', 'phone'],
                'item' => fn ($p) => $item($p->id, $p->name, trim("{$p->partner_code} · {$p->phone}", ' ·'), "/app/partners/{$p->id}"),
            ],
            'products' => [
                'label' => 'Products / Services', 'permission' => 'products.view', 'portal' => false,
                'query' => fn () => Product::query(),
                'columns' => ['name'],
                'item' => fn ($p) => $item($p->id, $p->name, '₹'.number_format((float) $p->amount, 2), self::listUrl('/app/products', $p->name)),
            ],
            'supplier_products' => [
                'label' => 'Supplier Products', 'permission' => 'supplies.view', 'portal' => false,
                'query' => fn () => SupplierProduct::with('partner:id,name'),
                'columns' => ['name'],
                'item' => fn ($p) => $item($p->id, $p->name, $p->partner?->name, self::listUrl('/app/supplier-products', $p->name)),
            ],
            'supplies' => [
                'label' => 'Supplies', 'permission' => 'supplies.view', 'portal' => true,
                'query' => fn () => Supply::query(),
                'columns' => ['reference_number', 'invoice_number', 'product_name'],
                'item' => fn ($s) => $item($s->id, $s->reference_number, trim("{$s->invoice_number} · {$s->product_name}", ' ·'), self::listUrl("{$base}/supplies", $s->reference_number)),
            ],
            'sales' => [
                'label' => 'Sales', 'permission' => 'commissions.view', 'portal' => true,
                'query' => fn () => Sale::where('sale_type', 'sale'),
                'columns' => ['reference_number', 'customer_name', 'product_name'],
                // In the portal a sale recorded from a referral is shown under My Referrals.
                'item' => fn ($s) => $item($s->id, $s->reference_number, trim("{$s->customer_name} · {$s->product_name}", ' ·'),
                    $portal && $s->referral_id ? '/portal/referrals' : self::listUrl("{$base}/sales", $s->reference_number)),
            ],
            'referrals' => [
                'label' => 'Referrals', 'permission' => 'commissions.view', 'portal' => true,
                'query' => fn () => Sale::where('sale_type', 'referral'),
                'columns' => ['reference_number', 'customer_name', 'customer_phone'],
                'item' => fn ($s) => $item($s->id, $s->reference_number, trim("{$s->customer_name} · {$s->customer_phone}", ' ·'), self::listUrl("{$base}/referrals", $s->reference_number)),
            ],
            'assignments' => [
                'label' => 'Assignments', 'permission' => 'assignments.view', 'portal' => true,
                'query' => fn () => Assignment::query(),
                'columns' => ['title', 'reference_number'],
                'item' => fn ($a) => $item($a->id, $a->title, trim("{$a->reference_number} · ".str_replace('_', ' ', $a->status), ' ·'), "{$base}/assignments/{$a->id}"),
            ],
            'commissions' => [
                'label' => 'Commissions', 'permission' => 'commissions.view', 'portal' => true,
                'query' => fn () => Commission::with('partner:id,name'),
                'columns' => $portal ? ['transaction_reference'] : ['transaction_reference', 'partner.name'],
                'item' => fn ($c) => $item($c->id, $c->transaction_reference, trim(($portal ? '' : "{$c->partner?->name} · ").'₹'.number_format((float) $c->commission_amount, 2)." · {$c->status}", ' ·'),
                    // The portal earnings page also has a payments table, so no pre-filled search there.
                    $portal ? '/portal/commission-payments' : self::listUrl('/app/commissions', $c->transaction_reference)),
            ],
            'reseller_purchases' => [
                'label' => 'Reseller Purchases', 'permission' => 'resellers.view', 'portal' => true,
                'query' => fn () => ResellerPurchase::query(),
                'columns' => ['reference_number', 'product_name', 'resale_customer_name'],
                'item' => fn ($r) => $item($r->id, $r->reference_number, $r->product_name, self::listUrl("{$base}/reseller-purchases", $r->reference_number)),
            ],
        ];
    }
}
