<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AffiliateEnquiry;
use App\Models\AffiliateLink;
use App\Models\Assignment;
use App\Models\Commission;
use App\Models\LedgerEntry;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\ResellerPayment;
use App\Models\ResellerPurchase;
use App\Models\Sale;
use App\Models\Settlement;
use App\Models\SupplierPayment;
use App\Models\Supply;
use App\Models\User;
use App\Services\DocumentExpiryService;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

class DashboardController extends Controller
{
    public function index(Request $request, DocumentExpiryService $expiryService)
    {
        $user = $request->user();

        if ($user->isPartnerUser()) {
            return response()->json($this->partnerDashboard($user));
        }

        $businessId = $user->business_id;

        $partners = Partner::when($businessId, fn ($q) => $q->where('business_id', $businessId));
        $assignments = Assignment::when($businessId, fn ($q) => $q->where('business_id', $businessId));
        $commissions = Commission::when($businessId, fn ($q) => $q->where('business_id', $businessId));
        $settlements = Settlement::when($businessId, fn ($q) => $q->where('business_id', $businessId));
        $ledger = LedgerEntry::when($businessId, fn ($q) => $q->where('business_id', $businessId));

        $payable = (clone $ledger)->where('running_balance', '>', 0)->latest('id')->get()->unique('partner_id')->sum('running_balance');
        $receivable = (clone $ledger)->where('running_balance', '<', 0)->latest('id')->get()->unique('partner_id')->sum('running_balance');

        $topPartners = (clone $commissions)->where('status', '!=', 'rejected')
            ->selectRaw('partner_id, SUM(commission_amount) as total')
            ->groupBy('partner_id')
            ->orderByDesc('total')
            ->with('partner:id,name,partner_code')
            ->limit(5)
            ->get();

        return response()->json([
            'total_partners' => (clone $partners)->count(),
            'active_partners' => (clone $partners)->where('status', 'active')->count(),
            'pending_applications' => (clone $partners)->whereIn('status', ['applied', 'verification_pending'])->count(),
            'pending_assignments' => (clone $assignments)->whereIn('status', ['pending', 'accepted', 'in_progress'])->count(),
            'pending_commissions' => (clone $commissions)->where('status', 'pending')->count(),
            'payable_balance' => (float) $payable,
            'receivable_balance' => (float) abs($receivable),
            'pending_settlements' => (clone $settlements)->whereIn('status', ['draft', 'pending_approval'])->count(),
            'top_performing_partners' => $topPartners,
            'recent_activity' => Activity::latest()->limit(10)->get(['id', 'description', 'subject_type', 'subject_id', 'created_at']),
            'expiring_documents' => $expiryService->expiringWithin(30)->take(10)->values(),
            'by_partner_type' => $this->adminPartnerTypeOverview($businessId),
            'money' => $this->adminMoneySummary($businessId),
        ]);
    }

    /**
     * Scoped dashboard for a Partner Portal login — their own numbers only, nothing
     * business-wide. Shape depends on partner type: Supplier/Reseller/Service/Delivery each
     * track a genuinely different flow, while Affiliate/Referral/Sales share the same
     * Sale-based commission-earning shape.
     *
     * A partner can hold multiple active partner-type enrollments at once, so the top level
     * still carries the primary (legacy partners.partner_type_id) type's numbers for backward
     * compatibility, and `by_type` additionally carries every active enrollment's own
     * type-appropriate numbers so the Partner Portal dashboard can show one card-grid per
     * active type without duplicating the shared Total Partner Earnings figures (see
     * PartnerEarningsService, surfaced separately via /partner/earnings/summary).
     */
    protected function partnerDashboard(User $user): array
    {
        $partner = Partner::with('partnerType', 'partnerCategory')->findOrFail($user->partner_id);

        $data = [
            'partner' => $partner->only(['id', 'name', 'partner_code', 'status']),
            'partner_type' => $partner->partnerType?->only(['id', 'name', 'code']),
            'money' => $this->partnerMoneySummary($partner),
        ];

        $data += $this->dashboardForTypeCode($partner, $partner->partnerType?->code);

        $activeTypes = $partner->portalEnrollments()->with('partnerType')->get()
            ->pluck('partnerType')->filter()->unique('id')->values();

        // Every role this login holds (primary first) — the portal header lists them all.
        $data['partner_types'] = $activeTypes->sortBy(fn ($t) => $t->id === $partner->partner_type_id ? 0 : 1)
            ->map->only(['id', 'name', 'code'])->values()->all();

        if ($activeTypes->count() > 1 || ($activeTypes->count() === 1 && $activeTypes->first()->code !== $partner->partnerType?->code)) {
            $data['by_type'] = $activeTypes->mapWithKeys(fn ($type) => [
                $type->code => [
                    'partner_type_id' => $type->id,
                    'partner_type_name' => $type->name,
                ] + $this->dashboardForTypeCode($partner, $type->code),
            ])->all();
        }

        return $data;
    }

    /**
     * The whole money picture in plain words, for the admin:
     *  - waiting for approval: commissions created but not yet checked;
     *  - we need to pay: approved commissions owed to partners + unpaid supplier bills;
     *  - we need to collect: approved commissions partners owe us + unpaid reseller bills;
     *  - paid out / collected this month: money that actually moved.
     */
    protected function adminMoneySummary(?int $businessId): array
    {
        $scope = fn ($q) => $q->when($businessId, fn ($b) => $b->where('business_id', $businessId));
        $due = ['approved', 'payable'];
        $monthStart = now()->startOfMonth()->toDateString();

        $commissionSum = fn (string $direction, array $statuses) => (float) $scope(Commission::query())
            ->where('direction', $direction)->whereIn('status', $statuses)->sum('commission_amount');

        $supplierBalance = (float) $scope(Supply::query())->sum('amount') - (float) $scope(Supply::query())->sum('amount_paid');
        $resellerBalance = (float) $scope(ResellerPurchase::query())->sum('amount') - (float) $scope(ResellerPurchase::query())->sum('amount_paid');

        $paidOut = (float) $scope(Payment::query())->where('status', 'completed')->where('direction', 'to_partner')->where('payment_date', '>=', $monthStart)->sum('amount')
            + (float) $scope(SupplierPayment::query())->where('payment_date', '>=', $monthStart)->sum('amount');
        $collected = (float) $scope(Payment::query())->where('status', 'completed')->where('direction', 'from_partner')->where('payment_date', '>=', $monthStart)->sum('amount')
            + (float) $scope(ResellerPayment::query())->where('payment_date', '>=', $monthStart)->sum('amount');

        return [
            'awaiting_approval_count' => $scope(Commission::query())->where('status', 'pending')->count(),
            'awaiting_approval_to_pay' => $commissionSum('to_partner', ['pending']),
            'awaiting_approval_to_collect' => $commissionSum('from_partner', ['pending']),
            'commissions_to_pay' => $commissionSum('to_partner', $due),
            'supplier_bills_to_pay' => $supplierBalance,
            'total_to_pay' => $commissionSum('to_partner', $due) + $supplierBalance,
            'commissions_to_collect' => $commissionSum('from_partner', $due),
            'reseller_bills_to_collect' => $resellerBalance,
            'total_to_collect' => $commissionSum('from_partner', $due) + $resellerBalance,
            'paid_out_this_month' => $paidOut,
            'collected_this_month' => $collected,
        ];
    }

    /**
     * The partner's money picture in plain words, across every role they hold.
     */
    protected function partnerMoneySummary(Partner $partner): array
    {
        $commissions = Commission::where('partner_id', $partner->id);
        $sum = fn (string $direction, array $statuses) => (float) (clone $commissions)
            ->where('direction', $direction)->whereIn('status', $statuses)->sum('commission_amount');
        $payments = Payment::where('partner_id', $partner->id)->where('status', 'completed');

        $supplies = Supply::where('partner_id', $partner->id);
        $purchases = ResellerPurchase::where('partner_id', $partner->id);
        $supplierBalance = (float) (clone $supplies)->sum('amount') - (float) (clone $supplies)->sum('amount_paid');
        $resellerBalance = (float) (clone $purchases)->sum('amount') - (float) (clone $purchases)->sum('amount_paid');

        return [
            'waiting_approval' => $sum('to_partner', ['pending']),
            'commission_owed_to_you' => $sum('to_partner', ['approved', 'payable']),
            'supplies_owed_to_you' => $supplierBalance,
            'owed_to_you' => $sum('to_partner', ['approved', 'payable']) + $supplierBalance,
            'commission_you_owe' => $sum('from_partner', ['approved', 'payable', 'pending']),
            'purchases_you_owe' => $resellerBalance,
            'you_owe' => $sum('from_partner', ['approved', 'payable', 'pending']) + $resellerBalance,
            'paid_to_you' => (float) (clone $payments)->where('direction', 'to_partner')->sum('amount')
                + (float) SupplierPayment::where('partner_id', $partner->id)->sum('amount'),
            'you_paid' => (float) (clone $payments)->where('direction', 'from_partner')->sum('amount')
                + (float) ResellerPayment::where('partner_id', $partner->id)->sum('amount'),
        ];
    }

    /**
     * Admin view of each partner type's own flow (requirements: Supplier, Service, Delivery,
     * Affiliate/Referral/Sales, Reseller) — the same figures each partner sees, summed.
     */
    protected function adminPartnerTypeOverview(?int $businessId): array
    {
        $scope = fn ($q) => $q->when($businessId, fn ($b) => $b->where('business_id', $businessId));
        $partnersWith = fn (array $codes) => $scope(Partner::query())
            ->where(fn ($q) => $q->whereHas('partnerType', fn ($t) => $t->whereIn('code', $codes))
                ->orWhereHas('enrollments.partnerType', fn ($t) => $t->whereIn('code', $codes)))
            ->count();

        $supplies = $scope(Supply::query());
        $purchases = $scope(ResellerPurchase::query());
        $sales = $scope(Sale::query());
        $saleCommissions = $scope(Commission::query())->whereNotNull('sale_id');

        $work = function (string $type) use ($scope) {
            $assignments = $scope(Assignment::query())->where('assignment_type', $type);
            $commissions = $scope(Commission::query())->whereHas('assignment', fn ($a) => $a->where('assignment_type', $type));
            $open = ['pending', 'accepted', 'in_progress', 'picked_up', 'out_for_delivery'];

            return [
                'open_count' => (clone $assignments)->whereIn('status', $open)->count(),
                'completed_count' => (clone $assignments)->whereIn('status', ['completed', 'delivered'])->count(),
                'failed_count' => (clone $assignments)->where('status', 'failed')->count(),
                'total_amount' => (float) (clone $assignments)->sum('transaction_amount'),
                'commission_due_to_us' => (float) (clone $commissions)->where('direction', 'from_partner')->whereIn('status', ['pending', 'approved', 'payable'])->sum('commission_amount'),
                'commission_received' => (float) (clone $commissions)->where('direction', 'from_partner')->where('status', 'paid')->sum('commission_amount'),
                'payout_pending' => (float) (clone $commissions)->where('direction', 'to_partner')->whereIn('status', ['pending', 'approved', 'payable'])->sum('commission_amount'),
                'payout_paid' => (float) (clone $commissions)->where('direction', 'to_partner')->where('status', 'paid')->sum('commission_amount'),
            ];
        };

        $supplied = (float) (clone $supplies)->sum('amount');
        $suppliedPaid = (float) (clone $supplies)->sum('amount_paid');
        $purchased = (float) (clone $purchases)->sum('amount');
        $purchasedPaid = (float) (clone $purchases)->sum('amount_paid');

        return [
            'supplier' => [
                'partners' => $partnersWith(['supplier']),
                'supply_count' => (clone $supplies)->count(),
                'total_quantity' => (float) (clone $supplies)->sum('quantity'),
                'total_amount' => $supplied,
                'total_paid' => $suppliedPaid,
                'balance' => $supplied - $suppliedPaid,
            ],
            'service' => ['partners' => $partnersWith(['service'])] + $work('service'),
            'delivery' => ['partners' => $partnersWith(['delivery'])] + $work('delivery'),
            'sales' => [
                'partners' => $partnersWith(['sales', 'affiliate', 'referral']),
                'sales_count' => (clone $sales)->count(),
                'sales_value' => (float) (clone $sales)->sum('sale_amount'),
                'commission_total' => (float) (clone $saleCommissions)->where('status', '!=', 'rejected')->sum('commission_amount'),
                'commission_paid' => (float) (clone $saleCommissions)->where('status', 'paid')->sum('commission_amount'),
                'commission_pending' => (float) (clone $saleCommissions)->whereIn('status', ['pending', 'approved', 'payable'])->sum('commission_amount'),
            ],
            'reseller' => [
                'partners' => $partnersWith(['reseller']),
                'purchase_count' => (clone $purchases)->count(),
                'total_quantity' => (float) (clone $purchases)->sum('quantity'),
                'total_amount' => $purchased,
                'total_received' => $purchasedPaid,
                'balance' => $purchased - $purchasedPaid,
            ],
        ];
    }

    /** Commission % actually applied: base -> commission ratio (falls back to null when base is 0). */
    protected static function commissionPercent($commission): ?float
    {
        if (! $commission || ! (float) $commission->base_amount) {
            return null;
        }

        return round((float) $commission->commission_amount / (float) $commission->base_amount * 100, 2);
    }

    /** Service/Delivery work rows for the dashboard's recent-work table. */
    protected function recentWork(Partner $partner, string $type): array
    {
        return Assignment::where('partner_id', $partner->id)->where('assignment_type', $type)
            ->with('commissions')->latest('id')->limit(5)->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'reference_number' => $a->reference_number,
                'title' => $a->title,
                'customer_name' => $a->customer_name,
                'delivery_address' => $a->delivery_address,
                'status' => $a->status,
                'amount' => (float) $a->transaction_amount,
                'commission_percent' => $a->commission_percent !== null ? (float) $a->commission_percent : self::commissionPercent($a->commissions->first()),
                'commission_amount' => (float) $a->commissions->sum('commission_amount'),
                'payment_direction' => $a->payment_direction,
            ])->all();
    }

    /** Payments recorded against one partner's service/delivery work (plus unlinked ones). */
    protected function workPayments(Partner $partner, string $type): array
    {
        $payments = Payment::where('partner_id', $partner->id)->where('status', 'completed')
            ->where(fn ($q) => $q->whereNull('assignment_id')
                ->orWhereHas('assignment', fn ($a) => $a->where('assignment_type', $type)));

        return [
            'payments_to_partner' => (float) (clone $payments)->where('direction', 'to_partner')->sum('amount'),
            'payments_from_partner' => (float) (clone $payments)->where('direction', 'from_partner')->sum('amount'),
        ];
    }

    protected function dashboardForTypeCode(Partner $partner, ?string $code): array
    {
        return match ($code) {
            'supplier' => $this->supplierDashboard($partner),
            'reseller' => $this->resellerDashboard($partner),
            'service' => $this->serviceDashboard($partner),
            'delivery' => $this->deliveryDashboard($partner),
            'affiliate', 'referral', 'sales' => $this->salesPipelineDashboard($partner),
            default => $this->genericPartnerDashboard($partner),
        };
    }

    /** The shape every partner type had before type-specific dashboards existed — still used by Business/Agent Partner. */
    protected function genericPartnerDashboard(Partner $partner): array
    {
        $assignments = Assignment::where('partner_id', $partner->id);
        $commissions = Commission::where('partner_id', $partner->id);
        $settlements = Settlement::where('partner_id', $partner->id);

        return [
            'pending_assignments' => (clone $assignments)->whereIn('status', ['pending', 'accepted', 'in_progress'])->count(),
            'pending_commissions' => (clone $commissions)->where('status', 'pending')->count(),
            'current_balance' => $partner->currentBalance(),
            'pending_settlements' => (clone $settlements)->whereIn('status', ['draft', 'pending_approval'])->count(),
            'commission_earned' => (float) (clone $commissions)->where('status', '!=', 'rejected')->sum('commission_amount'),
            'commission_paid' => (float) (clone $commissions)->where('status', 'paid')->sum('commission_amount'),
            'commission_pending' => (float) (clone $commissions)->whereIn('status', ['pending', 'approved', 'payable'])->sum('commission_amount'),
        ];
    }

    protected function supplierDashboard(Partner $partner): array
    {
        $supplies = Supply::where('partner_id', $partner->id);

        return [
            'supplier_reference_number' => $partner->partner_code,
            'supplier_type' => $partner->partnerCategory?->name,
            'total_supplied_amount' => (float) (clone $supplies)->sum('amount'),
            'total_paid_amount' => (float) (clone $supplies)->sum('amount_paid'),
            'outstanding_balance' => (float) (clone $supplies)->sum('amount') - (float) (clone $supplies)->sum('amount_paid'),
            'pending_supplies_count' => (clone $supplies)->where('payment_status', '!=', 'paid')->count(),
            'pending_payments_count' => (clone $supplies)->where('payment_status', '!=', 'paid')->count(),
            'total_supplies_count' => (clone $supplies)->count(),
            'total_quantity_received' => (float) (clone $supplies)->sum('quantity'),
            'products_supplied_count' => (clone $supplies)->distinct()->count('product_name'),
            'supplier_payments_count' => SupplierPayment::where('partner_id', $partner->id)->count(),
            'recent_supplies' => (clone $supplies)->latest('id')->limit(5)->get([
                'id', 'reference_number', 'product_name', 'quantity', 'amount', 'amount_paid', 'payment_status', 'supply_date',
            ]),
        ];
    }

    /** Mirrors supplierDashboard exactly — same shape, over ResellerPurchase, money owed the other way. */
    protected function resellerDashboard(Partner $partner): array
    {
        $purchases = ResellerPurchase::where('partner_id', $partner->id);

        return [
            'reseller_reference_number' => $partner->partner_code,
            'total_purchases' => (clone $purchases)->count(),
            'total_purchase_value' => (float) (clone $purchases)->sum('amount'),
            'total_paid' => (float) (clone $purchases)->sum('amount_paid'),
            'total_outstanding' => (float) (clone $purchases)->sum('amount') - (float) (clone $purchases)->sum('amount_paid'),
            'pending_payments_count' => (clone $purchases)->where('payment_status', '!=', 'paid')->count(),
            'total_quantity_purchased' => (float) (clone $purchases)->sum('quantity'),
            'products_purchased_count' => (clone $purchases)->distinct()->count('product_name'),
            'reseller_payments_count' => ResellerPayment::where('partner_id', $partner->id)->count(),
            'recent_purchases' => (clone $purchases)->latest('id')->limit(5)->get([
                'id', 'reference_number', 'product_name', 'quantity', 'amount', 'amount_paid', 'payment_status', 'purchase_date',
            ]),
        ];
    }

    /**
     * Service Partner tracks assignments (see Assignment's service-specific fields) plus a
     * commission split by direction — Model A (customer_pays_partner) is what the partner
     * owes us, Model B (customer_pays_lizyweb) is what we owe the partner. See
     * CommissionCalculationService::generateForAssignment.
     */
    protected function serviceDashboard(Partner $partner): array
    {
        $assignments = Assignment::where('partner_id', $partner->id)->where('assignment_type', 'service');
        // Only service-work commissions — a partner who also sells must not see sale commissions here.
        $commissions = Commission::where('partner_id', $partner->id)
            ->whereHas('assignment', fn ($a) => $a->where('assignment_type', 'service'));

        return $this->workPayments($partner, 'service') + [
            'pending_work' => (clone $assignments)->whereIn('status', ['pending', 'accepted', 'in_progress'])->count(),
            'in_progress_work' => (clone $assignments)->where('status', 'in_progress')->count(),
            'completed_work' => (clone $assignments)->where('status', 'completed')->count(),
            'total_work_amount' => (float) (clone $assignments)->sum('transaction_amount'),
            'recent_work' => $this->recentWork($partner, 'service'),
            'current_balance' => $partner->currentBalance(),
            'commission_due_to_lizyweb' => (float) (clone $commissions)->where('direction', 'from_partner')->whereIn('status', ['pending', 'approved', 'payable'])->sum('commission_amount'),
            'commission_owed_to_lizyweb_paid' => (float) (clone $commissions)->where('direction', 'from_partner')->where('status', 'paid')->sum('commission_amount'),
            'payout_pending' => (float) (clone $commissions)->where('direction', 'to_partner')->whereIn('status', ['pending', 'approved', 'payable'])->sum('commission_amount'),
            'payout_paid' => (float) (clone $commissions)->where('direction', 'to_partner')->where('status', 'paid')->sum('commission_amount'),
        ];
    }

    /** Delivery Partner — generic assignment/commission numbers plus delivery-status counts. */
    protected function deliveryDashboard(Partner $partner): array
    {
        $assignments = Assignment::where('partner_id', $partner->id)->where('assignment_type', 'delivery');
        $commissions = Commission::where('partner_id', $partner->id)
            ->whereHas('assignment', fn ($a) => $a->where('assignment_type', 'delivery'));

        return [
            // Delivery-only commission figures (override the partner-wide generic ones below).
            'commission_earned' => (float) (clone $commissions)->where('status', '!=', 'rejected')->sum('commission_amount'),
            'commission_paid' => (float) (clone $commissions)->where('status', 'paid')->sum('commission_amount'),
            'commission_pending' => (float) (clone $commissions)->whereIn('status', ['pending', 'approved', 'payable'])->sum('commission_amount'),
            'pending_assignments' => (clone $assignments)->whereIn('status', ['pending', 'accepted', 'in_progress'])->count(),
            'total_deliveries' => (clone $assignments)->count(),
            'total_delivery_amount' => (float) (clone $assignments)->sum('transaction_amount'),
            'recent_work' => $this->recentWork($partner, 'delivery'),
        ] + $this->workPayments($partner, 'delivery') + $this->genericPartnerDashboard($partner) + [
            'picked_up_count' => (clone $assignments)->where('status', 'picked_up')->count(),
            'out_for_delivery_count' => (clone $assignments)->where('status', 'out_for_delivery')->count(),
            'delivered_count' => (clone $assignments)->where('status', 'delivered')->count(),
            'failed_count' => (clone $assignments)->where('status', 'failed')->count(),
        ];
    }

    /**
     * Shared by Affiliate/Referral/Sales Partner — all three earn commission the same way
     * (Sale against the Product/Service catalog, see CommissionCalculationService::
     * generateForSale), just described with different vocabulary in the frontend.
     */
    protected function salesPipelineDashboard(Partner $partner): array
    {
        // A referral and the sale admin recorded for it are one lead: count the referral and read
        // the amount/commission from its linked sale, never the pair twice.
        $sales = Sale::where('partner_id', $partner->id)->whereNull('referral_id');
        // Only sale commissions — service/delivery commissions belong to those dashboards.
        $commissions = Commission::where('partner_id', $partner->id)->whereNotNull('sale_id');

        // Stored storefront tracking totals across this partner's affiliate links.
        $links = AffiliateLink::where('partner_id', $partner->id);

        return [
            'link_clicks' => (int) (clone $links)->sum('clicks_count'),
            // Every enquiry credited to the partner — from their links or entered by hand.
            'link_enquiries' => AffiliateEnquiry::where('partner_id', $partner->id)->count(),
            'link_conversions' => (int) (clone $links)->sum('conversions_count'),
            'total_sales' => (clone $sales)->count(),
            'total_conversions' => (clone $sales)->where('status', 'confirmed')->count(),
            // Every actual sale, plus older referrals completed with their own amount (a referral
            // with a recorded sale is counted through that sale, not twice).
            'total_sales_value' => (float) Sale::where('partner_id', $partner->id)
                ->where(fn ($q) => $q->where('sale_type', '!=', 'referral')->orWhereDoesntHave('linkedSale'))
                ->sum('sale_amount'),
            'total_commission' => (float) (clone $commissions)->where('status', '!=', 'rejected')->sum('commission_amount'),
            'commission_paid' => (float) (clone $commissions)->where('status', 'paid')->sum('commission_amount'),
            'commission_pending' => (float) (clone $commissions)->whereIn('status', ['pending', 'approved', 'payable'])->sum('commission_amount'),
            'recent_sales' => (clone $sales)->with('commission', 'product:id,commission_percent', 'linkedSale.commission')->latest('id')->limit(5)->get()
                ->map(function ($sale) {
                    // A referral shows the actual sale admin recorded for it, once there is one.
                    $shown = $sale->linkedSale ?? $sale;
                    $commission = $shown->commission ?? $sale->commission;

                    return [
                        'id' => $sale->id,
                        'reference_number' => $sale->reference_number,
                        'product_name' => $shown->product_name,
                        'sale_type' => $sale->sale_type,
                        'customer_name' => $sale->customer_name,
                        'quantity' => $shown->quantity,
                        'sale_amount' => (float) $shown->sale_amount,
                        'sale_date' => $sale->sale_date,
                        'status' => $sale->status,
                        'commission_percent' => self::commissionPercent($commission) ?? ($sale->product?->commission_percent !== null ? (float) $sale->product->commission_percent : null),
                        'commission_amount' => (float) ($commission?->commission_amount ?? 0),
                        'commission_status' => $commission?->status,
                    ];
                })->all(),
        ];
    }
}
