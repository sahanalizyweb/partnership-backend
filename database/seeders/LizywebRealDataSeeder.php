<?php

namespace Database\Seeders;

use App\Http\Controllers\Api\AffiliateLinkController;
use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\CommissionController;
use App\Http\Controllers\Api\PartnerController;
use App\Http\Controllers\Api\PartnerTypeEnrollmentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ResellerPaymentController;
use App\Http\Controllers\Api\ResellerPurchaseController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\SupplierPaymentController;
use App\Http\Controllers\Api\SupplierProductController;
use App\Http\Controllers\Api\SupplyController;
use App\Models\AffiliateLink;
use App\Models\Assignment;
use App\Models\Business;
use App\Models\Commission;
use App\Models\Partner;
use App\Models\PartnerType;
use App\Models\Product;
use App\Models\ResellerPurchase;
use App\Models\Sale;
use App\Models\SupplierProduct;
use App\Models\Supply;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * One-time Lizyweb real data for all 7 partner types. Run by hand:
 *   php artisan db:seed --class=LizywebRealDataSeeder
 *
 * Every record goes through the same controller actions the admin screens call (acting as the
 * admin login, read-only), so partner codes, reference numbers, commissions, ledger entries,
 * balances and statuses come out exactly as if entered in the app. Safe to run twice: each
 * record is looked up first (phone / invoice / title / customer phone) and each flow only runs
 * the steps still missing. Never creates portal logins; never touches users, roles,
 * businesses, partner types or business sources.
 */
class LizywebRealDataSeeder extends Seeder
{
    public const BUSINESS_CODE = 'lizyweb';

    /** Acting admin — only read, never modified. */
    public const ADMIN_EMAIL = 'admin.partnership@lizyweb.in';

    protected User $admin;

    protected Business $business;

    /** @var array<string, Product> */
    protected array $products = [];

    /** @var string[] */
    protected array $notes = [];

    public function run(): void
    {
        $this->admin = User::where('email', self::ADMIN_EMAIL)->firstOrFail();
        $this->business = Business::where('code', self::BUSINESS_CODE)->firstOrFail();

        // A business-scoped admin always writes into their own business — refuse rather than
        // silently put the data into a different business than BUSINESS_CODE.
        if ($this->admin->business_id && (int) $this->admin->business_id !== (int) $this->business->id) {
            throw new RuntimeException(self::ADMIN_EMAIL.' belongs to another business than '.self::BUSINESS_CODE.'.');
        }

        auth()->setUser($this->admin);

        DB::transaction(function () {
            $this->seedProducts();
            $this->seedSupplier();
            $this->seedService();
            $this->seedDelivery();
            $this->seedAffiliate();
            $this->seedReferral();
            $this->seedSales();
            $this->seedReseller();
            $this->report();
        });
    }

    // ---------------------------------------------------------------- 0. Products / Services

    protected function seedProducts(): void
    {
        $rows = [
            ['V Type Pump 1 HP', 4500, 10, '1 HP V Type motor pump for home and farm water supply.'],
            ['Openwell Pump 1 HP', 5500, 10, '1 HP open well pump for lifting water from open wells.'],
            ['20 Meter Wire', 1530, 5, '20 meter cable wire for pump connection.'],
            ['Submersible Pump 1.5 HP (18 Stage)', 10500, 8, '1.5 HP 18 stage submersible pump for borewells.'],
        ];

        foreach ($rows as [$name, $amount, $percent, $description]) {
            $product = Product::where('business_id', $this->business->id)->where('name', $name)->first();

            // No lizymart_url sent: the Lizy Mart page stays empty (admin adds it in the app),
            // and ProductController::store only contacts the shop when a URL is given.
            if (! $product) {
                $product = $this->act(ProductController::class, 'store', [
                    'business_id' => $this->business->id,
                    'name' => $name,
                    'description' => $description,
                    'amount' => $amount,
                    'commission_percent' => $percent,
                    'status' => 'active',
                ]);
            }

            $this->products[$name] = $product->fresh();
        }
    }

    // ---------------------------------------------------------------- 1. Supplier Partner

    protected function seedSupplier(): void
    {
        $partner = $this->ensurePartner('MV Diamond Pumps', 'business', '9003322468', 'supplier', null);

        $supplierProducts = [];
        foreach ([['V Type Pump 1 HP', 4500], ['Openwell Pump 1 HP', 5500], ['20 Meter Wire', 1530]] as [$name, $price]) {
            $supplierProducts[$name] = SupplierProduct::where('partner_id', $partner->id)->where('name', $name)->first()
                ?? $this->act(SupplierProductController::class, 'store', [
                    'partner_id' => $partner->id,
                    'name' => $name,
                    'standard_price' => $price,
                    'status' => 'active',
                ]);
        }

        $supplies = [
            ['INV001492', '2026-10-02', ['product_name' => 'Openwell Pump 1 HP + 20 Meter Wire', 'amount' => 7030], 'LZM-2026-0256', 'Nova travels kunnnamkulam', 7030],
            ['INV001487', '2026-09-30', ['product_name' => 'Motor Pumps', 'amount' => 8000], 'LZM-2026-0253', 'Mss transport Kallakuruchi', 4000],
            ['INV001494', '2026-10-03', ['supplier_product_id' => $supplierProducts['V Type Pump 1 HP']->id, 'unit_price' => 4500], 'LZM-2026-0258', 'Mss transport Vellore', null],
        ];

        foreach ($supplies as [$invoice, $date, $productFields, $order, $transport, $pay]) {
            $supply = Supply::where('partner_id', $partner->id)->where('invoice_number', $invoice)->first()
                ?? $this->act(SupplyController::class, 'store', $productFields + [
                    'partner_id' => $partner->id,
                    'quantity' => 1,
                    'supply_date' => $date,
                    'invoice_number' => $invoice,
                    'notes' => "Order {$order}, sent by {$transport}",
                ]);

            if ($pay && ! $supply->payments()->exists()) {
                $this->act(SupplierPaymentController::class, 'store', [
                    'supply_id' => $supply->id,
                    'amount' => $pay,
                    'payment_date' => $date,
                    'notes' => 'Sample data',
                ]);
            }
        }
    }

    // ---------------------------------------------------------------- 2. Service Partner

    protected function seedService(): void
    {
        $partner = $this->ensurePartner('Karthik Raja', 'individual', '9000000102', 'service', '45, Nehru Street, Coimbatore, Tamil Nadu 641001');

        $jobs = [
            [[
                'title' => 'Install Openwell Pump 1 HP at farm', 'location' => 'Pollachi',
                'assigned_date' => '2026-10-01', 'due_date' => '2026-10-03',
                'customer_name' => 'R. Selvam', 'customer_phone' => '9000000201',
                'transaction_amount' => 3000, 'commission_percent' => 20, 'payment_direction' => 'customer_pays_lizyweb',
            ], 'completed', 3000],
            [[
                'title' => 'Repair V Type Pump motor', 'location' => 'Tiruppur',
                'assigned_date' => '2026-10-03', 'due_date' => '2026-10-06',
                'customer_name' => 'M. Lakshmi', 'customer_phone' => '9000000202',
                'transaction_amount' => 1800, 'commission_percent' => 20, 'payment_direction' => 'customer_pays_partner',
            ], 'in_progress', null],
            [[
                'title' => 'Borewell pump check', 'location' => 'Erode',
                'assigned_date' => '2026-10-04', 'due_date' => '2026-10-07',
                'customer_name' => 'P. Dinesh', 'customer_phone' => '9000000203',
                'transaction_amount' => 1200, 'commission_percent' => 15, 'payment_direction' => 'customer_pays_lizyweb',
            ], 'pending', null],
        ];

        foreach ($jobs as [$fields, $target, $completeAmount]) {
            $job = $this->ensureAssignment($partner, 'service', $fields);
            $this->advance($job, [
                'accepted' => ['accept', []],
                'in_progress' => ['start', []],
                'completed' => ['complete', ['transaction_amount' => $completeAmount]],
            ], $target);
        }

        // J1: customer paid Lizy -> approve + pay the partner.
        $this->approveAndPay($this->assignmentCommission($partner, 'Install Openwell Pump 1 HP at farm'), '2026-10-04', 'UPI');
    }

    // ---------------------------------------------------------------- 3. Delivery Partner

    protected function seedDelivery(): void
    {
        $partner = $this->ensurePartner('Imran Khan', 'individual', '9000000103', 'delivery', '12, Fairlands Road, Salem, Tamil Nadu 636001');

        $deliveries = [
            ['Deliver V Type Pump to Erode', '2026-10-01', '2026-10-03', 'V Type Pump 1 HP', 'A. Murugan', '9000000204',
                '12, Gandhi Road, Erode, Tamil Nadu 638001', 4500, 'customer_pays_lizyweb', 'delivered'],
            ['Deliver Openwell Pump to Karur', '2026-10-03', '2026-10-05', 'Openwell Pump 1 HP', 'S. Priya', '9000000205',
                '8, Bazaar Street, Karur, Tamil Nadu 639001', 5500, 'customer_pays_partner', 'out_for_delivery'],
            ['Deliver 20 Meter Wire to Salem', '2026-10-04', '2026-10-06', '20 Meter Wire', 'P. Venkat', '9000000206',
                '5, Cherry Road, Salem, Tamil Nadu 636007', 1530, 'customer_pays_lizyweb', 'accepted'],
        ];

        foreach ($deliveries as [$title, $assigned, $due, $product, $customer, $phone, $address, $amount, $direction, $target]) {
            $job = $this->ensureAssignment($partner, 'delivery', [
                'title' => $title, 'location' => 'Lizy warehouse',
                'assigned_date' => $assigned, 'due_date' => $due,
                'product_id' => $this->products[$product]->id,
                'customer_name' => $customer, 'customer_phone' => $phone, 'delivery_address' => $address,
                'transaction_amount' => $amount, 'commission_percent' => 5, 'payment_direction' => $direction,
            ]);
            $this->advance($job, [
                'accepted' => ['accept', []],
                'picked_up' => ['pickUp', []],
                'out_for_delivery' => ['outForDelivery', []],
                'delivered' => ['deliver', []],
            ], $target);
        }

        $this->approveAndPay($this->assignmentCommission($partner, 'Deliver V Type Pump to Erode'), '2026-10-04', 'Bank Transfer');
    }

    // ---------------------------------------------------------------- 4. Affiliate Partner

    protected function seedAffiliate(): void
    {
        $this->ensurePartner('Divya Online Deals', 'business', '9000000104', 'affiliate', '22, Race Course Road, Coimbatore, Tamil Nadu 641018');

        // Affiliate links are NOT created here: AffiliateLinkController builds each link's URL
        // from the product's Lizy Mart page, which is left empty until admin adds it in the app.
        foreach (['V Type Pump 1 HP (10%, active)', 'Openwell Pump 1 HP (10%, active)', '20 Meter Wire (5%, inactive)'] as $link) {
            $this->notes[] = "Affiliate link skipped: {$link} — add the product's Lizy Mart page, then create the link in the app.";
        }
    }

    // ---------------------------------------------------------------- 5. Referral Partner

    protected function seedReferral(): void
    {
        $partner = $this->ensurePartner('Suresh Kumar', 'individual', '9000000105', 'referral', '7, Temple Street, Tiruppur, Tamil Nadu 641601');

        $referrals = [
            ['N. Ganesh', '9000000207', 5500, '2026-10-01', 10, 'Needs Openwell Pump for farm'],
            ['T. Meena', '9000000208', 4500, '2026-10-03', 10, 'Asked for V Type Pump price'],
            ['K. Ravi', '9000000209', 10500, '2026-10-02', 8, 'Borewell pump enquiry'],
        ];

        $saved = [];
        foreach ($referrals as [$customer, $phone, $expected, $date, $percent, $note]) {
            $saved[$phone] = Sale::where('partner_id', $partner->id)->where('sale_type', 'referral')->where('customer_phone', $phone)->first()
                ?? $this->act(SaleController::class, 'store', [
                    'sale_type' => 'referral',
                    'partner_id' => $partner->id,
                    'customer_name' => $customer,
                    'customer_phone' => $phone,
                    'expected_amount' => $expected,
                    'commission_percent' => $percent,
                    'sale_date' => $date,
                    'notes' => $note,
                ]);
        }

        // R1 completed: record its Sale (this marks the referral completed and creates the commission).
        $r1 = $saved['9000000207']->fresh();
        $sale = $r1->linkedSale
            ?? $this->act(SaleController::class, 'store', [
                'referral_id' => $r1->id,
                'product_id' => $this->products['Openwell Pump 1 HP']->id,
                'quantity' => 1,
                'sale_amount' => 5500,
                'sale_date' => '2026-10-01',
                'payment_status' => 'paid',
                'payment_method' => 'UPI',
            ]);
        $this->confirmSale($sale);
        $commission = $sale->fresh()->commission;
        if ($commission && $commission->status === 'pending') {
            $this->act(CommissionController::class, 'approve', [], ['commission' => $commission]);
        }

        // R3 cancelled.
        $r3 = $saved['9000000209']->fresh();
        if ($r3->status !== 'cancelled') {
            $this->act(SaleController::class, 'update', ['status' => 'cancelled'], ['sale' => $r3]);
        }
    }

    // ---------------------------------------------------------------- 6. Sales Partner

    protected function seedSales(): void
    {
        $partner = $this->ensurePartner('Anitha Traders', 'business', '9000000106', 'sales', '30, Big Bazaar Street, Coimbatore, Tamil Nadu 641001');

        $sales = [
            'SL1' => ['R. Mohan', '9000000210', 'V Type Pump 1 HP', 2, 9000, '2026-09-30', 10, ['payment_status' => 'paid'], 'UPI', true],
            'SL2' => ['S. Banu', '9000000211', 'Submersible Pump 1.5 HP (18 Stage)', 1, 10500, '2026-10-02', 8, ['payment_status' => 'partial', 'amount_paid' => 5000], 'Bank Transfer', true],
            'SL3' => ['L. Karthik', '9000000212', '20 Meter Wire', 3, 4590, '2026-10-03', 5, ['payment_status' => 'unpaid'], 'Cash', false],
        ];

        foreach ($sales as $key => [$customer, $phone, $product, $qty, $amount, $date, $percent, $payment, $method, $confirm]) {
            $sale = Sale::where('partner_id', $partner->id)->where('sale_type', 'sale')->where('customer_phone', $phone)->first()
                ?? $this->act(SaleController::class, 'store', $payment + [
                    'partner_id' => $partner->id,
                    'product_id' => $this->products[$product]->id,
                    'customer_name' => $customer,
                    'customer_phone' => $phone,
                    'quantity' => $qty,
                    'sale_amount' => $amount,
                    'sale_date' => $date,
                    'commission_rate' => $percent,
                    'payment_method' => $method,
                ]);

            if ($confirm) {
                $this->confirmSale($sale);
            }

            if ($key === 'SL1') {
                $this->approveAndPay($sale->fresh()->commission, '2026-10-04', 'UPI');
            }
        }
    }

    // ---------------------------------------------------------------- 7. Reseller Partner

    protected function seedReseller(): void
    {
        $partner = $this->ensurePartner('Sri Murugan Pump Centre', 'business', '9000000107', 'reseller', '18, Mettupalayam Road, Coimbatore, Tamil Nadu 641043');

        // Brand mode: Lizyweb / LizyMart brand (same settings the Enrollments tab saves).
        $enrollment = $partner->enrollments()->whereHas('partnerType', fn ($t) => $t->where('code', 'reseller'))->first();
        if ($enrollment && ($enrollment->settings['brand_mode'] ?? null) !== 'lizyweb') {
            $this->act(PartnerTypeEnrollmentController::class, 'update', [
                'settings' => array_merge($enrollment->settings ?? [], ['brand_mode' => 'lizyweb', 'brand_name' => null]),
            ], ['partner' => $partner, 'enrollment' => $enrollment]);
        }

        $purchases = [
            ['V Type Pump 1 HP', 5, 4500, '2026-09-29', [], [15000, '2026-09-30']],
            ['Openwell Pump 1 HP', 2, 5500, '2026-10-01', ['resale_customer_name' => 'Velusamy', 'resale_amount' => 12500, 'resale_date' => '2026-10-02'], [11000, '2026-10-01']],
            ['20 Meter Wire', 10, 1530, '2026-10-03', [], null],
        ];

        foreach ($purchases as [$product, $qty, $unit, $date, $resale, $pay]) {
            $productId = $this->products[$product]->id;
            $purchase = ResellerPurchase::where('partner_id', $partner->id)->where('product_id', $productId)->whereDate('purchase_date', $date)->first()
                ?? $this->act(ResellerPurchaseController::class, 'store', $resale + [
                    'partner_id' => $partner->id,
                    'product_id' => $productId,
                    'quantity' => $qty,
                    'unit_price' => $unit,
                    'purchase_date' => $date,
                ]);

            if ($pay && ! $purchase->payments()->exists()) {
                $this->act(ResellerPaymentController::class, 'store', [
                    'reseller_purchase_id' => $purchase->id,
                    'amount' => $pay[0],
                    'payment_date' => $pay[1],
                ]);
            }
        }
    }

    // ---------------------------------------------------------------- Shared flows

    /**
     * Finds the partner by phone in this business, or creates it through PartnerController::store
     * (which also adds the role to an existing profile that lacks it), then runs
     * Mark Verified -> Activate as on the Partner Details page.
     */
    protected function ensurePartner(string $name, string $entityType, string $phone, string $typeCode, ?string $address): Partner
    {
        $type = PartnerType::where('code', $typeCode)->whereNull('business_id')->first()
            ?? PartnerType::where('code', $typeCode)->firstOrFail();

        $elsewhere = Partner::where('phone', $phone)->where('business_id', '!=', $this->business->id)->first();
        if ($elsewhere && ! Partner::where('phone', $phone)->where('business_id', $this->business->id)->exists()) {
            throw new RuntimeException("Phone {$phone} already belongs to {$elsewhere->name} ({$elsewhere->partner_code}) in another business — not touching it.");
        }

        $partner = Partner::where('business_id', $this->business->id)->where('phone', $phone)->first();
        $holdsRole = $partner && ((int) $partner->partner_type_id === (int) $type->id
            || $partner->enrollments()->where('partner_type_id', $type->id)->exists());

        if (! $holdsRole) {
            $this->act(PartnerController::class, 'store', array_filter([
                'business_id' => $this->business->id,
                'partner_type_id' => $type->id,
                'entity_type' => $entityType,
                'name' => $name,
                'phone' => $phone,
                'address' => $address,
            ], fn ($v) => $v !== null));
            $partner = Partner::where('business_id', $this->business->id)->where('phone', $phone)->firstOrFail();
        }

        if (in_array($partner->status, ['applied', 'verification_pending'], true)) {
            $this->act(PartnerController::class, 'verify', [], ['partner' => $partner]);
            $partner->refresh();
        }
        if ($partner->status === 'verified') {
            $this->act(PartnerController::class, 'reactivate', [], ['partner' => $partner]);
            $partner->refresh();
        }

        return $partner;
    }

    protected function ensureAssignment(Partner $partner, string $type, array $fields): Assignment
    {
        return Assignment::where('partner_id', $partner->id)->where('assignment_type', $type)->where('title', $fields['title'])->first()
            ?? $this->act(AssignmentController::class, 'store', $fields + ['partner_id' => $partner->id, 'assignment_type' => $type]);
    }

    /**
     * Moves an assignment through its lifecycle up to $target, running only the steps still
     * ahead of its current status (so a second run does nothing).
     *
     * @param  array<string, array{0: string, 1: array}>  $steps  status reached => [controller action, input]
     */
    protected function advance(Assignment $assignment, array $steps, string $target): void
    {
        $order = array_merge(['pending'], array_keys($steps));
        $current = array_search($assignment->fresh()->status, $order, true);
        $goal = array_search($target, $order, true);

        if ($current === false) {
            $this->notes[] = "{$assignment->reference_number} is in status '{$assignment->status}' — left as is.";

            return;
        }

        for ($i = $current + 1; $i <= $goal; $i++) {
            [$action, $input] = $steps[$order[$i]];
            $this->act(AssignmentController::class, $action, array_filter($input, fn ($v) => $v !== null), ['assignment' => $assignment->fresh()]);
        }
    }

    protected function assignmentCommission(Partner $partner, string $title): ?Commission
    {
        $assignment = Assignment::where('partner_id', $partner->id)->where('title', $title)->first();

        return $assignment?->commissions()->where('status', '!=', 'rejected')->first();
    }

    /** Commissions page: Approve, then Pay Partner (record-payment). Skips steps already done. */
    protected function approveAndPay(?Commission $commission, string $date, string $method): void
    {
        if (! $commission) {
            $this->notes[] = 'Expected commission was not created by the system — nothing approved/paid.';

            return;
        }

        if ($commission->status === 'pending') {
            $this->act(CommissionController::class, 'approve', [], ['commission' => $commission]);
            $commission->refresh();
        }
        if ($commission->status === 'approved') {
            $this->act(CommissionController::class, 'recordPayment', ['payment_date' => $date, 'payment_method' => $method], ['commission' => $commission]);
        }
    }

    protected function confirmSale(Sale $sale): void
    {
        if ($sale->fresh()->status !== 'confirmed') {
            $this->act(SaleController::class, 'update', ['status' => 'confirmed'], ['sale' => $sale->fresh()]);
        }
    }

    /**
     * Calls a controller action exactly as its API route would, as the admin user. Returns the
     * model/array the action produced. Validation/abort errors stop the whole seeder (rollback).
     */
    protected function act(string $controller, string $method, array $input = [], array $args = []): mixed
    {
        $request = Request::create('/seeder', 'POST', $input);
        $request->setUserResolver(fn () => $this->admin);
        app()->instance('request', $request);

        try {
            $result = app()->call([app($controller), $method], ['request' => $request] + $args);
        } catch (ValidationException $e) {
            throw new RuntimeException(class_basename($controller)."@{$method} validation failed: ".json_encode($e->errors()), 0, $e);
        } catch (HttpExceptionInterface $e) {
            throw new RuntimeException(class_basename($controller)."@{$method} refused: ".$e->getMessage(), 0, $e);
        }

        return $result instanceof JsonResponse ? $result->getOriginalContent() : $result;
    }

    // ---------------------------------------------------------------- Summary + checks

    protected function report(): void
    {
        $out = $this->command;
        $biz = $this->business->id;
        $partner = fn (string $phone) => Partner::where('business_id', $biz)->where('phone', $phone)->first();
        $money = fn ($v) => $v === null ? '-' : number_format((float) $v, 2, '.', '');
        $commissionCell = fn (?Commission $c) => $c ? $money($c->commission_amount).' '.$c->direction.' / '.$c->status : '-';

        $out?->info('PARTNERS ('.self::BUSINESS_CODE.')');
        $out?->table(['Type', 'Code', 'Name', 'Phone', 'Status', 'Ledger balance'], collect([
            ['supplier', '9003322468'], ['service', '9000000102'], ['delivery', '9000000103'], ['affiliate', '9000000104'],
            ['referral', '9000000105'], ['sales', '9000000106'], ['reseller', '9000000107'],
        ])->map(function ($r) use ($partner, $money) {
            $p = $partner($r[1]);

            return [$r[0], $p?->partner_code, $p?->name, $r[1], $p?->status, $money($p?->currentBalance())];
        })->all());

        // 1. Supplier
        $supplier = $partner('9003322468');
        $supplies = Supply::where('partner_id', $supplier->id)->whereIn('invoice_number', ['INV001492', 'INV001487', 'INV001494'])->orderBy('supply_date')->get();
        $out?->info('1. SUPPLIER — MV Diamond Pumps');
        $out?->table(['Ref', 'Invoice', 'Date', 'Product', 'Amount', 'Paid', 'Balance', 'Status'], $supplies->map(fn ($s) => [
            $s->reference_number, $s->invoice_number, $s->supply_date->toDateString(), $s->product_name,
            $money($s->amount), $money($s->amount_paid), $money($s->balance_amount), $s->payment_status,
        ])->push(['', '', '', 'TOTAL', $money($supplies->sum('amount')), $money($supplies->sum('amount_paid')), $money($supplies->sum('balance_amount')), ''])->all());

        // 2 + 3. Service / Delivery
        foreach ([['2. SERVICE — Karthik Raja', '9000000102'], ['3. DELIVERY — Imran Khan', '9000000103']] as [$label, $phone]) {
            $out?->info($label);
            $out?->table(['Ref', 'Title', 'Amount', 'Comm %', 'Pays', 'Status', 'Commission'],
                Assignment::where('partner_id', $partner($phone)->id)->orderBy('id')->get()->map(fn ($a) => [
                    $a->reference_number, $a->title, $money($a->transaction_amount), $money($a->commission_percent),
                    $a->payment_direction, $a->status, $commissionCell($a->commissions()->first()),
                ])->all());
        }

        // 4. Affiliate
        $out?->info('4. AFFILIATE — Divya Online Deals');
        $out?->table(['Code', 'Product', 'Comm %', 'Status', 'Target URL'],
            AffiliateLink::with('product')->where('partner_id', $partner('9000000104')->id)->orderBy('id')->get()->map(fn ($l) => [
                $l->code, $l->product?->name, $money($l->commission_percent), $l->status, $l->target_url,
            ])->all());

        // 5. Referral
        $out?->info('5. REFERRAL — Suresh Kumar');
        $out?->table(['Ref', 'Customer', 'Expected', 'Comm %', 'Status', 'Linked sale', 'Sale status', 'Commission'],
            Sale::with('linkedSale.commission')->where('partner_id', $partner('9000000105')->id)->where('sale_type', 'referral')->orderBy('id')->get()->map(fn ($r) => [
                $r->reference_number, $r->customer_name, $money($r->expected_amount), $money($r->commission_percent), $r->status,
                $r->linkedSale ? $r->linkedSale->reference_number.' ('.$money($r->linkedSale->sale_amount).', '.$r->linkedSale->payment_status.')' : '-',
                $r->linkedSale?->status ?? '-', $commissionCell($r->linkedSale?->commission),
            ])->all());

        // 6. Sales
        $out?->info('6. SALES — Anitha Traders');
        $out?->table(['Ref', 'Customer', 'Product', 'Qty', 'Amount', 'Paid', 'Payment', 'Method', 'Status', 'Commission'],
            Sale::with('commission')->where('partner_id', $partner('9000000106')->id)->where('sale_type', 'sale')->orderBy('id')->get()->map(fn ($s) => [
                $s->reference_number, $s->customer_name, $s->product_name, $s->quantity, $money($s->sale_amount),
                $money($s->amount_paid), $s->payment_status, $s->payment_method, $s->status, $commissionCell($s->commission),
            ])->all());

        // 7. Reseller
        $reseller = $partner('9000000107');
        $purchases = ResellerPurchase::where('partner_id', $reseller->id)->orderBy('purchase_date')->get();
        $out?->info('7. RESELLER — Sri Murugan Pump Centre (brand mode: '
            .($reseller->enrollments()->whereHas('partnerType', fn ($t) => $t->where('code', 'reseller'))->first()?->settings['brand_mode'] ?? '-').')');
        $out?->table(['Ref', 'Product', 'Qty', 'Amount', 'Paid', 'Balance', 'Status', 'Resale'], $purchases->map(fn ($p) => [
            $p->reference_number, $p->product_name, $p->quantity, $money($p->amount), $money($p->amount_paid), $money($p->balance_amount),
            $p->payment_status, $p->resale_status === 'resold' ? "{$p->resale_customer_name} {$money($p->resale_amount)} on {$p->resale_date->toDateString()}" : 'not resold',
        ])->all());

        // Required checks — any failure rolls the whole run back.
        $failures = [];
        foreach (['amount' => 19530, 'amount_paid' => 11030, 'balance_amount' => 8500] as $field => $expected) {
            if (abs($supplies->sum($field) - $expected) > 0.01) {
                $failures[] = "Supplier {$field} = {$supplies->sum($field)}, expected {$expected}";
            }
        }
        foreach (['V Type Pump 1 HP' => 7500, 'Openwell Pump 1 HP' => 0, '20 Meter Wire' => 15300] as $name => $expected) {
            $balance = $purchases->firstWhere('product_name', $name)?->balance_amount;
            if ($balance === null || abs($balance - $expected) > 0.01) {
                $failures[] = "Reseller {$name} balance = ".($balance ?? 'missing').", expected {$expected}";
            }
        }

        foreach ($this->notes as $note) {
            $out?->warn($note);
        }

        if ($failures) {
            throw new RuntimeException("Checks failed — nothing saved:\n".implode("\n", $failures));
        }

        $out?->info('Checks passed: supplier 19530 / 11030 / 8500; reseller balances 7500 / 0 / 15300.');
    }
}
