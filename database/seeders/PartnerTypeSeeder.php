<?php

namespace Database\Seeders;

use App\Models\PartnerCategory;
use App\Models\PartnerType;
use Illuminate\Database\Seeder;

class PartnerTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['name' => 'Sales Partner', 'code' => 'sales'],
            ['name' => 'Delivery Partner', 'code' => 'delivery'],
            ['name' => 'Service Partner', 'code' => 'service'],
            ['name' => 'Referral Partner', 'code' => 'referral'],
            ['name' => 'Business Partner', 'code' => 'business'],
            ['name' => 'Supplier Partner', 'code' => 'supplier'],
            ['name' => 'Agent Partner', 'code' => 'agent'],
            ['name' => 'Affiliate Partner', 'code' => 'affiliate'],
            ['name' => 'Reseller Partner', 'code' => 'reseller'],
        ] as $type) {
            PartnerType::firstOrCreate(
                ['code' => $type['code'], 'business_id' => null],
                $type + ['status' => 'active']
            );
        }

        // Supplier Partner can be one of these three — a Partner Category, same mechanism
        // every other partner type already uses for sub-classification (Admin can still
        // add/edit/delete via the existing Partner Categories screen).
        $supplierType = PartnerType::where('code', 'supplier')->whereNull('business_id')->first();
        if ($supplierType) {
            foreach (['Manufacturer', 'Distributor', 'Wholesaler'] as $name) {
                PartnerCategory::firstOrCreate(
                    ['name' => $name, 'partner_type_id' => $supplierType->id, 'business_id' => null],
                    ['status' => 'active']
                );
            }
        }
    }
}
