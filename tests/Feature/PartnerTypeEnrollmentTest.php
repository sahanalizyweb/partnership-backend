<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Partner;
use App\Models\PartnerType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PartnerTypeEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_partner_creation_produces_exactly_one_primary_enrollment(): void
    {
        $business = Business::create(['name' => 'Lizyweb', 'code' => 'lizyweb', 'status' => 'active']);
        $serviceType = PartnerType::create(['name' => 'Service Partner', 'code' => 'service', 'status' => 'active']);

        $partner = Partner::create([
            'business_id' => $business->id, 'partner_code' => 'LZY-AB-0001',
            'partner_type_id' => $serviceType->id, 'entity_type' => 'business',
            'name' => 'ABC Enterprises', 'phone' => '9990001111', 'status' => 'active',
        ]);
        $partner->enrollments()->create([
            'business_id' => $business->id, 'partner_type_id' => $serviceType->id,
            'enrollment_code' => 'LZY-AB-0001-SERVICE', 'status' => 'active', 'is_primary' => true,
        ]);

        $this->assertSame(1, $partner->enrollments()->count());
        $this->assertSame(1, $partner->enrollments()->where('is_primary', true)->count());
        $this->assertSame($serviceType->id, $partner->primaryEnrollment->partner_type_id);
    }

    public function test_a_partner_can_enroll_in_a_second_type_but_not_the_same_type_twice(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $business = Business::create(['name' => 'Lizyweb', 'code' => 'lizyweb', 'status' => 'active']);
        $serviceType = PartnerType::create(['name' => 'Service Partner', 'code' => 'service', 'status' => 'active']);
        $salesType = PartnerType::create(['name' => 'Sales Partner', 'code' => 'sales', 'status' => 'active']);

        $partner = Partner::create([
            'business_id' => $business->id, 'partner_code' => 'LZY-AB-0002',
            'partner_type_id' => $serviceType->id, 'entity_type' => 'business',
            'name' => 'XYZ Traders', 'phone' => '9990002222', 'status' => 'active',
        ]);
        $partner->enrollments()->create([
            'business_id' => $business->id, 'partner_type_id' => $serviceType->id,
            'enrollment_code' => 'LZY-AB-0002-SERVICE', 'status' => 'active', 'is_primary' => true,
        ]);

        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('Super Administrator');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson("/api/v1/partners/{$partner->id}/enrollments", ['partner_type_id' => $salesType->id]);
        $response->assertStatus(201);
        $response->assertJsonPath('enrollment_code', 'LZY-AB-0002-SALES');

        $duplicate = $this->postJson("/api/v1/partners/{$partner->id}/enrollments", ['partner_type_id' => $salesType->id]);
        $duplicate->assertStatus(422);

        $this->assertSame(2, $partner->enrollments()->count());
    }
}
