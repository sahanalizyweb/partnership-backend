<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Partner;
use App\Models\PartnerType;
use App\Models\Supply;
use App\Models\SupplierPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks in the required payment-status logic: a Supply's "amount"/"quantity supplied" is the
 * value of goods received, never money already paid. Status must derive only from actual
 * SupplierPayment records recorded against it (Supply::recalcPaymentStatus).
 */
class SupplierPaymentStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function makeSupply(): Supply
    {
        $business = Business::create(['name' => 'Lizyweb', 'code' => 'lizyweb', 'status' => 'active']);
        $supplierType = PartnerType::create(['name' => 'Supplier Partner', 'code' => 'supplier', 'status' => 'active']);

        $partner = Partner::create([
            'business_id' => $business->id, 'partner_code' => 'LZY-SUP-0001',
            'partner_type_id' => $supplierType->id, 'entity_type' => 'business',
            'name' => 'Acme Supplies', 'phone' => '9990001234', 'status' => 'active',
        ]);

        return Supply::create([
            'business_id' => $business->id, 'partner_id' => $partner->id,
            'reference_number' => 'SUPPLY-0001', 'product_name' => 'Widgets',
            'quantity' => 10, 'unit_price' => 5000, 'amount' => 50000,
            'supply_date' => now()->toDateString(), 'amount_paid' => 0, 'payment_status' => 'unpaid',
        ]);
    }

    public function test_supply_with_no_payment_is_unpaid(): void
    {
        $supply = $this->makeSupply();

        $this->assertSame('unpaid', $supply->payment_status);
        $this->assertSame(50000.0, $supply->balance_amount);
    }

    public function test_supply_with_partial_payment_is_partially_paid(): void
    {
        $supply = $this->makeSupply();

        SupplierPayment::create([
            'business_id' => $supply->business_id, 'partner_id' => $supply->partner_id, 'supply_id' => $supply->id,
            'reference_number' => 'SUPPAY-0001', 'amount' => 20000, 'payment_date' => now()->toDateString(),
        ]);
        $supply->increment('amount_paid', 20000);
        $supply->recalcPaymentStatus();

        $this->assertSame('partial', $supply->fresh()->payment_status);
        $this->assertSame(30000.0, $supply->fresh()->balance_amount);
    }

    public function test_supply_fully_paid_is_marked_paid(): void
    {
        $supply = $this->makeSupply();

        SupplierPayment::create([
            'business_id' => $supply->business_id, 'partner_id' => $supply->partner_id, 'supply_id' => $supply->id,
            'reference_number' => 'SUPPAY-0002', 'amount' => 50000, 'payment_date' => now()->toDateString(),
        ]);
        $supply->increment('amount_paid', 50000);
        $supply->recalcPaymentStatus();

        $this->assertSame('paid', $supply->fresh()->payment_status);
    }

    public function test_supply_overpaid_is_still_marked_paid(): void
    {
        $supply = $this->makeSupply();

        SupplierPayment::create([
            'business_id' => $supply->business_id, 'partner_id' => $supply->partner_id, 'supply_id' => $supply->id,
            'reference_number' => 'SUPPAY-0003', 'amount' => 55000, 'payment_date' => now()->toDateString(),
        ]);
        $supply->increment('amount_paid', 55000);
        $supply->recalcPaymentStatus();

        $this->assertSame('paid', $supply->fresh()->payment_status);
    }

    public function test_receiving_goods_alone_never_marks_a_supply_as_paid(): void
    {
        // Regression for the reported bug: creating a Supply for a large amount must never,
        // by itself, be treated as money already paid to the supplier.
        $supply = $this->makeSupply();

        $this->assertSame(0.0, (float) $supply->amount_paid);
        $this->assertNotSame('paid', $supply->payment_status);
    }
}
