<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Business;
use App\Models\Partner;
use App\Models\PartnerType;
use App\Models\ResellerPurchase;
use App\Models\Sale;
use App\Services\CommissionCalculationService;
use App\Services\PartnerEarningsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Encodes the owner's exact test scenario: a partner enrolled in Service + Sales + Affiliate +
 * Reseller earns ₹10,000 + ₹5,000 + ₹2,000 + ₹3,000 = ₹20,000 Total Partner Earnings,
 * backend-calculated, with the underlying transactions staying completely separate.
 */
class PartnerEarningsSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_multi_enrollment_partner_earnings_combine_correctly(): void
    {
        $business = Business::create(['name' => 'Lizyweb', 'code' => 'lizyweb', 'status' => 'active']);

        $serviceType = PartnerType::create(['name' => 'Service Partner', 'code' => 'service', 'status' => 'active']);
        $salesType = PartnerType::create(['name' => 'Sales Partner', 'code' => 'sales', 'status' => 'active']);
        $affiliateType = PartnerType::create(['name' => 'Affiliate Partner', 'code' => 'affiliate', 'status' => 'active']);
        $resellerType = PartnerType::create(['name' => 'Reseller Partner', 'code' => 'reseller', 'status' => 'active']);

        $partner = Partner::create([
            'business_id' => $business->id,
            'partner_code' => 'LZY-AB-0001',
            'partner_type_id' => $serviceType->id,
            'entity_type' => 'business',
            'name' => 'ABC Enterprises',
            'phone' => '9990001111',
            'status' => 'active',
        ]);

        $serviceEnrollment = $partner->enrollments()->create([
            'business_id' => $business->id, 'partner_type_id' => $serviceType->id,
            'enrollment_code' => 'LZY-AB-0001-SERVICE', 'status' => 'active', 'is_primary' => true,
        ]);
        $salesEnrollment = $partner->enrollments()->create([
            'business_id' => $business->id, 'partner_type_id' => $salesType->id,
            'enrollment_code' => 'LZY-AB-0001-SALES', 'status' => 'active', 'is_primary' => false,
        ]);
        $affiliateEnrollment = $partner->enrollments()->create([
            'business_id' => $business->id, 'partner_type_id' => $affiliateType->id,
            'enrollment_code' => 'LZY-AB-0001-AFFILIATE', 'status' => 'active', 'is_primary' => false,
        ]);
        $resellerEnrollment = $partner->enrollments()->create([
            'business_id' => $business->id, 'partner_type_id' => $resellerType->id,
            'enrollment_code' => 'LZY-AB-0001-RESELLER', 'status' => 'active', 'is_primary' => false,
        ]);

        $calculator = new CommissionCalculationService;

        // Service earning ₹10,000 (Model B: we hold the amount, pay the partner 100%).
        $assignment = Assignment::create([
            'business_id' => $business->id, 'partner_id' => $partner->id,
            'partner_type_enrollment_id' => $serviceEnrollment->id,
            'assignment_type' => 'service', 'title' => 'Interior service',
            'status' => 'completed', 'reference_number' => 'SRV-000001',
            'transaction_amount' => 10000, 'commission_percent' => 0,
            'payment_direction' => 'customer_pays_lizyweb',
        ]);
        $calculator->generateForAssignment($assignment)->update(['status' => 'paid']);

        // Sales earning ₹5,000 (100% override rate lands exactly on the sale amount).
        $salesSale = Sale::create([
            'business_id' => $business->id, 'partner_id' => $partner->id,
            'partner_type_enrollment_id' => $salesEnrollment->id,
            'product_name' => 'Sales product', 'unit_price' => 5000, 'quantity' => 1,
            'sale_amount' => 5000, 'sale_date' => now()->toDateString(),
            'reference_number' => 'SAL-000001',
        ]);
        $calculator->generateForSale($salesSale, 100)->update(['status' => 'paid']);

        // Affiliate earning ₹2,000.
        $affiliateSale = Sale::create([
            'business_id' => $business->id, 'partner_id' => $partner->id,
            'partner_type_enrollment_id' => $affiliateEnrollment->id,
            'product_name' => 'Affiliate product', 'unit_price' => 2000, 'quantity' => 1,
            'sale_amount' => 2000, 'sale_date' => now()->toDateString(),
            'reference_number' => 'SAL-000002',
        ]);
        $calculator->generateForSale($affiliateSale, 100)->update(['status' => 'paid']);

        // Reseller margin ₹3,000 (purchase ₹7,000 -> resold for ₹10,000).
        ResellerPurchase::create([
            'business_id' => $business->id, 'partner_id' => $partner->id,
            'partner_type_enrollment_id' => $resellerEnrollment->id,
            'product_name' => 'Reseller product', 'quantity' => 1, 'unit_price' => 7000, 'amount' => 7000,
            'purchase_date' => now()->toDateString(), 'amount_paid' => 7000, 'payment_status' => 'paid',
            'resale_customer_name' => 'Walk-in Customer', 'resale_amount' => 10000,
            'resale_date' => now()->toDateString(), 'resale_status' => 'resold',
            'reference_number' => 'RES-000001',
        ]);

        $summary = (new PartnerEarningsService)->summary($partner->fresh());

        $this->assertSame(20000.0, $summary['overall']['total_earnings']);
        $this->assertSame(10000.0, $summary['by_type']['service']['earnings_total']);
        $this->assertSame(5000.0, $summary['by_type']['sales']['earnings_total']);
        $this->assertSame(2000.0, $summary['by_type']['affiliate']['earnings_total']);
        $this->assertSame(3000.0, $summary['by_type']['reseller']['earnings_total']);

        // Prove it's a computed aggregate, not a merge of the underlying rows.
        $this->assertSame(3, \App\Models\Commission::count());
        $this->assertSame(1, ResellerPurchase::count());
    }

    /**
     * Regression: a legacy commission (partner_type_enrollment_id still null, as if created
     * before this feature shipped) must be attributed to the partner's PRIMARY enrollment
     * only. If a partner later gains a second enrollment, that legacy row must not also be
     * counted under the new enrollment — otherwise Total Partner Earnings would double-count it.
     */
    public function test_legacy_null_enrollment_commission_is_not_double_counted_across_enrollments(): void
    {
        $business = Business::create(['name' => 'Lizyweb', 'code' => 'lizyweb', 'status' => 'active']);
        $serviceType = PartnerType::create(['name' => 'Service Partner', 'code' => 'service', 'status' => 'active']);
        $salesType = PartnerType::create(['name' => 'Sales Partner', 'code' => 'sales', 'status' => 'active']);

        $partner = Partner::create([
            'business_id' => $business->id, 'partner_code' => 'LZY-AB-0009',
            'partner_type_id' => $serviceType->id, 'entity_type' => 'business',
            'name' => 'Legacy Co', 'phone' => '9990009999', 'status' => 'active',
        ]);
        $serviceEnrollment = $partner->enrollments()->create([
            'business_id' => $business->id, 'partner_type_id' => $serviceType->id,
            'enrollment_code' => 'LZY-AB-0009-SERVICE', 'status' => 'active', 'is_primary' => true,
        ]);
        $salesEnrollment = $partner->enrollments()->create([
            'business_id' => $business->id, 'partner_type_id' => $salesType->id,
            'enrollment_code' => 'LZY-AB-0009-SALES', 'status' => 'active', 'is_primary' => false,
        ]);

        // Simulates a pre-migration commission: no partner_type_enrollment_id at all.
        \App\Models\Commission::create([
            'business_id' => $business->id, 'partner_id' => $partner->id,
            'transaction_reference' => 'LEGACY-1', 'base_amount' => 4000, 'commission_amount' => 4000,
            'direction' => 'to_partner', 'status' => 'paid',
        ]);

        $summary = (new PartnerEarningsService)->summary($partner->fresh());

        $this->assertSame(4000.0, $summary['by_type']['service']['earnings_total']);
        $this->assertSame(0.0, $summary['by_type']['sales']['earnings_total']);
        $this->assertSame(4000.0, $summary['overall']['total_earnings']);
    }
}
