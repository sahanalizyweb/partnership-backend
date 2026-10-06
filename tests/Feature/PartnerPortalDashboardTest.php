<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Partner;
use App\Models\PartnerType;
use App\Models\Supply;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A partner enrolled in more than one active partner type must see every active type's own
 * dashboard numbers (DashboardController::partnerDashboard by_type), not just their primary
 * type's — and a single-type partner's response shape must stay unchanged (no `by_type` key).
 */
class PartnerPortalDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_multi_enrollment_partner_sees_a_dashboard_block_per_active_type(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $business = Business::create(['name' => 'Lizyweb', 'code' => 'lizyweb', 'status' => 'active']);
        $serviceType = PartnerType::create(['name' => 'Service Partner', 'code' => 'service', 'status' => 'active']);
        $supplierType = PartnerType::create(['name' => 'Supplier Partner', 'code' => 'supplier', 'status' => 'active']);

        $partner = Partner::create([
            'business_id' => $business->id, 'partner_code' => 'LZY-AB-0002',
            'partner_type_id' => $serviceType->id, 'entity_type' => 'business',
            'name' => 'Multi Co', 'phone' => '9990002222', 'status' => 'active',
        ]);

        $serviceEnrollment = $partner->enrollments()->create([
            'business_id' => $business->id, 'partner_type_id' => $serviceType->id,
            'enrollment_code' => 'LZY-AB-0002-SERVICE', 'status' => 'active', 'is_primary' => true,
        ]);
        $partner->enrollments()->create([
            'business_id' => $business->id, 'partner_type_id' => $supplierType->id,
            'enrollment_code' => 'LZY-AB-0002-SUPPLIER', 'status' => 'active', 'is_primary' => false,
        ]);

        Supply::create([
            'business_id' => $business->id, 'partner_id' => $partner->id,
            'partner_type_enrollment_id' => $serviceEnrollment->id,
            'reference_number' => 'SUPPLY-1000', 'product_name' => 'Parts',
            'quantity' => 2, 'unit_price' => 2500, 'amount' => 5000,
            'supply_date' => now()->toDateString(), 'amount_paid' => 0, 'payment_status' => 'unpaid',
        ]);

        $user = User::create([
            'business_id' => $business->id, 'partner_id' => $partner->id,
            'name' => 'Multi Co User', 'email' => 'multico@example.test', 'password' => 'secret-pass',
        ]);
        $user->assignRole('Partner');

        Sanctum::actingAs($user, ['*']);
        $response = $this->getJson('/api/v1/dashboard');

        $response->assertOk();
        $response->assertJsonPath('partner_type.code', 'service');
        $response->assertJsonStructure(['by_type' => ['service', 'supplier']]);
        $this->assertEquals(5000, $response->json('by_type.supplier.total_supplied_amount'));
    }

    public function test_single_enrollment_partner_dashboard_has_no_by_type_key(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $business = Business::create(['name' => 'Lizyweb', 'code' => 'lizyweb', 'status' => 'active']);
        $serviceType = PartnerType::create(['name' => 'Service Partner', 'code' => 'service', 'status' => 'active']);

        $partner = Partner::create([
            'business_id' => $business->id, 'partner_code' => 'LZY-AB-0003',
            'partner_type_id' => $serviceType->id, 'entity_type' => 'business',
            'name' => 'Solo Co', 'phone' => '9990003333', 'status' => 'active',
        ]);
        $partner->enrollments()->create([
            'business_id' => $business->id, 'partner_type_id' => $serviceType->id,
            'enrollment_code' => 'LZY-AB-0003-SERVICE', 'status' => 'active', 'is_primary' => true,
        ]);

        $user = User::create([
            'business_id' => $business->id, 'partner_id' => $partner->id,
            'name' => 'Solo Co User', 'email' => 'soloco@example.test', 'password' => 'secret-pass',
        ]);
        $user->assignRole('Partner');

        Sanctum::actingAs($user, ['*']);
        $response = $this->getJson('/api/v1/dashboard');

        $response->assertOk();
        $response->assertJsonMissingPath('by_type');
    }
}
