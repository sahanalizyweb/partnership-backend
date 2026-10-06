<?php

namespace Tests\Feature;

use App\Models\AffiliateLink;
use App\Models\Business;
use App\Models\Commission;
use App\Models\Partner;
use App\Models\PartnerType;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AffiliateAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_affiliate_link_attribution_generates_one_commission_and_blocks_double_attribution(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $business = Business::create(['name' => 'Lizyweb', 'code' => 'lizyweb', 'status' => 'active']);
        $affiliateType = PartnerType::create(['name' => 'Affiliate Partner', 'code' => 'affiliate', 'status' => 'active']);

        $partner = Partner::create([
            'business_id' => $business->id, 'partner_code' => 'LZY-AB-0003',
            'partner_type_id' => $affiliateType->id, 'entity_type' => 'individual',
            'name' => 'Affiliate Partner One', 'phone' => '9990003333', 'status' => 'active',
        ]);
        $enrollment = $partner->enrollments()->create([
            'business_id' => $business->id, 'partner_type_id' => $affiliateType->id,
            'enrollment_code' => 'LZY-AB-0003-AFFILIATE', 'status' => 'active', 'is_primary' => true,
        ]);

        $link = AffiliateLink::create([
            'business_id' => $business->id, 'partner_id' => $partner->id,
            'partner_type_enrollment_id' => $enrollment->id, 'code' => 'ABC-LINK-001', 'status' => 'active',
        ]);

        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('Super Administrator');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/v1/sales', [
            'partner_id' => $partner->id,
            'affiliate_link_code' => $link->code,
            'product_name' => 'Widget', 'unit_price' => 1000, 'quantity' => 1,
            'sale_date' => now()->toDateString(), 'reference_number' => 'ORDER-0001',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('attribution_method', 'affiliate_link');
        $this->assertSame(1, Commission::count());
        $this->assertSame(1, Sale::count());

        // Re-attributing the same order (e.g. a later manual admin attribution attempt) must be rejected.
        $duplicate = $this->postJson('/api/v1/sales', [
            'partner_id' => $partner->id,
            'product_name' => 'Widget', 'unit_price' => 1000, 'quantity' => 1,
            'sale_date' => now()->toDateString(), 'reference_number' => 'ORDER-0001',
        ]);

        $duplicate->assertStatus(422);
        $this->assertSame(1, Sale::count());
        $this->assertSame(1, Commission::count());
    }
}
