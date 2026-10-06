<?php

namespace Tests\Feature;

use App\Models\AffiliateEnquiry;
use App\Models\AffiliateLink;
use App\Models\Business;
use App\Models\Partner;
use App\Models\PartnerType;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AffiliateEnquiryApiTest extends TestCase
{
    use RefreshDatabase;

    protected const KEY = 'test-website-key';

    protected AffiliateLink $link;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.website.api_key' => self::KEY]);

        $business = Business::create(['name' => 'Lizyweb', 'code' => 'lizyweb', 'status' => 'active']);
        $type = PartnerType::create(['name' => 'Affiliate Partner', 'code' => 'affiliate', 'status' => 'active']);
        $partner = Partner::create([
            'business_id' => $business->id, 'partner_code' => 'LIZ-AA-0001', 'partner_type_id' => $type->id,
            'entity_type' => 'business', 'name' => 'Affiliate One', 'phone' => '9990001111', 'status' => 'active',
        ]);
        $service = Product::create([
            'business_id' => $business->id, 'name' => 'Static Website Development', 'type' => 'service',
            'slug' => 'static-website-development', 'amount' => 15000, 'commission_percent' => 10, 'status' => 'active',
        ]);
        $this->link = AffiliateLink::create([
            'business_id' => $business->id, 'partner_id' => $partner->id, 'product_id' => $service->id,
            'code' => 'AFF-CODE-1', 'status' => 'active',
        ]);
    }

    protected function send(array $body, string $key = self::KEY)
    {
        return $this->withHeaders(['X-Website-Key' => $key])->postJson('/api/v1/public/affiliate-enquiries', $body + [
            'name' => 'Ravi Kumar', 'phone' => '98765 43210', 'email' => 'ravi@example.com',
            'message' => 'Need a website', 'service_slug' => 'static-website-development',
            'page_url' => 'https://lizyweb.in/Lizyweb/service.html?slug=static-website-development&ref=AFF-CODE-1',
        ]);
    }

    public function test_valid_enquiry_is_stored_under_the_link_partner(): void
    {
        $response = $this->send(['ref' => 'AFF-CODE-1', 'website_enquiry_id' => 'WEB-1']);

        $response->assertStatus(201)->assertJsonPath('status', 'new');
        $enquiry = AffiliateEnquiry::findOrFail($response->json('id'));
        $this->assertSame($this->link->partner_id, $enquiry->partner_id);
        $this->assertSame($this->link->product_id, $enquiry->product_id);
        $this->assertSame('link', $enquiry->source);
        $this->assertSame('9876543210', $enquiry->customer_phone);
        $this->assertSame(1, $this->link->fresh()->enquiries_count);
    }

    public function test_wrong_or_inactive_ref_is_rejected(): void
    {
        $this->send(['ref' => 'NO-SUCH-CODE'])->assertStatus(422)->assertJsonValidationErrors('ref');

        $this->link->update(['status' => 'inactive']);
        $this->send(['ref' => 'AFF-CODE-1'])->assertStatus(422);

        $this->assertSame(0, AffiliateEnquiry::count());
    }

    public function test_wrong_key_is_rejected(): void
    {
        $this->send(['ref' => 'AFF-CODE-1'], 'wrong-key')->assertStatus(401);
        $this->assertSame(0, AffiliateEnquiry::count());
    }

    public function test_service_link_manual_enquiry_and_convert_to_sale(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $this->seed(\Database\Seeders\BusinessSourceSeeder::class);
        $admin = \App\Models\User::factory()->create(['business_id' => null]);
        $admin->assignRole('Super Administrator');
        \Laravel\Sanctum\Sanctum::actingAs($admin, ['*']);
        $partner = $this->link->partner;

        // A Lizyweb service: type defaults to Service, slug fills from the name, no shop lookup.
        $service = $this->postJson('/api/v1/products', [
            'business_id' => $partner->business_id, 'name' => 'Logo Design', 'amount' => 3000, 'commission_percent' => 10,
        ])->assertStatus(201)->assertJsonPath('type', 'service')->assertJsonPath('slug', 'logo-design')
            ->assertJsonPath('website_url', 'https://lizyweb.in/Lizyweb/service.html?slug=logo-design');

        // Slug must be unique per business.
        $this->postJson('/api/v1/products', [
            'business_id' => $partner->business_id, 'name' => 'Logo Design', 'amount' => 1, 'commission_percent' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors('slug');

        $preview = $this->getJson("/api/v1/affiliate-links/preview?partner_id={$partner->id}&product_id={$service->json('id')}")->assertOk();
        $link = $this->postJson('/api/v1/affiliate-links', [
            'partner_id' => $partner->id, 'product_id' => $service->json('id'), 'code' => $preview->json('code'), 'commission_percent' => 12,
        ])->assertStatus(201);
        $this->assertSame(
            'https://lizyweb.in/Lizyweb/service.html?slug=logo-design&ref='.$preview->json('code'),
            $link->json('target_url'),
        );

        // Manual enquiry picks up the partner's active link for that service; a duplicate is blocked.
        $body = ['partner_id' => $partner->id, 'product_id' => $service->json('id'), 'customer_name' => 'Meena R', 'customer_phone' => '9000011111'];
        $enquiry = $this->postJson('/api/v1/affiliate-enquiries', $body)->assertStatus(201)
            ->assertJsonPath('source', 'manual')->assertJsonPath('affiliate_link_id', $link->json('id'));
        $this->postJson('/api/v1/affiliate-enquiries', $body)->assertStatus(422);

        // Convert to Sale: commission at the link's 12%, source Affiliate, enquiry converted, +1 conversion.
        $sale = $this->postJson('/api/v1/sales', [
            'affiliate_enquiry_id' => $enquiry->json('id'), 'product_id' => $service->json('id'),
            'affiliate_link_code' => $preview->json('code'), 'commission_rate' => 12,
            'sale_amount' => 5000, 'quantity' => 1, 'sale_date' => now()->toDateString(), 'payment_status' => 'paid',
        ])->assertStatus(201)->assertJsonPath('partner_id', $partner->id)->assertJsonPath('attribution_method', 'affiliate_link');

        $this->assertSame('600.00', $sale->json('commission.commission_amount'));
        $this->assertSame(\App\Models\BusinessSource::where('code', 'affiliate')->value('id'), $sale->json('business_source_id'));
        $converted = AffiliateEnquiry::find($enquiry->json('id'));
        $this->assertSame('converted', $converted->status);
        $this->assertSame($sale->json('id'), $converted->sale_id);
        $created = AffiliateLink::find($link->json('id'));
        $this->assertSame(1, $created->enquiries_count);
        $this->assertSame(1, $created->conversions_count);

        // Converting twice is refused.
        $this->postJson('/api/v1/sales', [
            'affiliate_enquiry_id' => $enquiry->json('id'), 'product_id' => $service->json('id'), 'quantity' => 1, 'sale_date' => now()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_duplicates_return_the_existing_enquiry(): void
    {
        $first = $this->send(['ref' => 'AFF-CODE-1', 'website_enquiry_id' => 'WEB-1'])->assertStatus(201);

        // Same website enquiry id re-sent.
        $this->send(['ref' => 'AFF-CODE-1', 'website_enquiry_id' => 'WEB-1'])
            ->assertStatus(200)->assertJsonPath('id', $first->json('id'))->assertJsonPath('duplicate', true);

        // Same phone + same link within 24 hours, under a new website id.
        $this->send(['ref' => 'AFF-CODE-1', 'website_enquiry_id' => 'WEB-2', 'phone' => '+91 98765-43210'])
            ->assertStatus(200);

        $this->assertSame(1, AffiliateEnquiry::count());
        $this->assertSame(1, $this->link->fresh()->enquiries_count);
    }
}
